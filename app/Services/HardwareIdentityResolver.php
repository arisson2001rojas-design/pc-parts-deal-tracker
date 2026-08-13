<?php

namespace App\Services;

use App\Dto\HardwareEvidence;
use App\Dto\IdentityResolution;
use App\Enums\IdentityResolutionState;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Read-only, deterministic identity resolver.
 *
 * Candidates should be preselected by authoritative MPN or variant evidence,
 * but an MPN lookup must not be scoped to component type. That allows this
 * resolver to expose a reused MPN in another component category as a conflict.
 */
final class HardwareIdentityResolver
{
    /** @var array<string, list<string>> */
    private const array CRITICAL_ATTRIBUTES = [
        'ssd' => ['capacity_gb', 'storage_type', 'interface', 'form_factor', 'heatsink'],
        'hdd' => ['capacity_gb', 'storage_type', 'interface', 'form_factor'],
        'sshd' => ['capacity_gb', 'storage_type', 'interface', 'form_factor'],
        'ram' => ['total_capacity_gb', 'module_count', 'module_capacity_gb', 'ddr_generation', 'speed_mhz', 'form_factor'],
        'gpu' => ['gpu_model', 'vram_gb', 'memory_type'],
        'cpu' => ['cpu_model', 'cpu_suffix', 'socket', 'packaging'],
        'motherboard' => ['board_model', 'chipset', 'socket', 'ram_generation', 'revision'],
        'psu' => ['psu_model', 'wattage_w', 'form_factor', 'revision'],
    ];

    public function __construct(
        private readonly HardwareEvidenceNormalizer $normalizer = new HardwareEvidenceNormalizer,
    ) {}

    /**
     * Candidate values may be HardwareEvidence instances, normalized arrays,
     * arrays shaped as ['id' => ..., 'evidence' => HardwareEvidence|array], or
     * model-like Arrayable objects with an optional getKey().
     *
     * @param  iterable<mixed>  $candidates
     */
    public function resolve(HardwareEvidence $evidence, iterable $candidates): IdentityResolution
    {
        $subject = $this->normalizer->fromArray($evidence->toArray());
        $normalizedCandidates = $this->normalizeCandidates($candidates);
        $comparisons = [];

        foreach ($normalizedCandidates as $candidate) {
            $comparisons[] = $this->compare($subject, $candidate['evidence'], $candidate['id'], $candidate['position']);
        }

        $relevant = array_values(array_filter($comparisons, static fn (array $comparison): bool => $comparison['relevant']));
        $withConflicts = array_values(array_filter($relevant, static fn (array $comparison): bool => $comparison['conflicts'] !== []));

        if ($withConflicts !== []) {
            return new IdentityResolution(
                state: IdentityResolutionState::Conflicting,
                candidateIds: $this->candidateIds($withConflicts),
                signals: $this->messages($relevant, 'signals'),
                conflicts: $this->messages($withConflicts, 'conflicts'),
                reason: 'Strong evidence contradicts one or more candidate identities.',
                suggestedAction: 'reject_automatic_resolution',
            );
        }

        $eligible = array_values(array_filter($relevant, static fn (array $comparison): bool => $comparison['eligible']));
        if (count($eligible) > 1) {
            return new IdentityResolution(
                state: IdentityResolutionState::Ambiguous,
                candidateIds: $this->candidateIds($eligible),
                signals: $this->messages($eligible, 'signals'),
                reason: 'More than one compatible hardware identity remains.',
                suggestedAction: 'review_candidates',
            );
        }

        if (count($eligible) === 1) {
            $candidate = $eligible[0];
            if ($candidate['strong'] && ! $subject->isUnsafeForVerification() && ! $candidate['evidence']->isUnsafeForVerification()) {
                return new IdentityResolution(
                    state: IdentityResolutionState::Verified,
                    matchedIdentityId: $candidate['id'],
                    candidateIds: $this->candidateIds($eligible),
                    signals: $candidate['signals'],
                    reason: 'Exact typed MPN, manufacturer, and component type agree without contradictions.',
                    suggestedAction: 'associate_identity',
                );
            }

            return new IdentityResolution(
                state: IdentityResolutionState::Probable,
                candidateIds: $this->candidateIds($eligible),
                signals: $candidate['signals'],
                reason: $subject->isUnsafeForVerification() || $candidate['evidence']->isUnsafeForVerification()
                    ? 'Compatible evidence exists, but listing condition, bundle, or marketplace risk prevents verification.'
                    : 'One compatible candidate has medium evidence but no complete authoritative match.',
                suggestedAction: 'review_candidate',
            );
        }

        if ($relevant === [] && $subject->canEstablishIdentity()) {
            return new IdentityResolution(
                state: IdentityResolutionState::Verified,
                signals: ['new_exact_manufacturer_mpn_identity'],
                reason: 'No compatible identity exists; authoritative manufacturer/MPN evidence can establish a new identity.',
                suggestedAction: 'create_identity',
            );
        }

        return new IdentityResolution(
            state: IdentityResolutionState::Unverified,
            candidateIds: $this->candidateIds($relevant),
            signals: $this->messages($relevant, 'signals'),
            reason: $relevant === []
                ? 'No compatible hardware identity candidate was found.'
                : 'Available evidence is too weak to identify a physical hardware variant.',
            suggestedAction: 'collect_more_evidence',
        );
    }

    /**
     * @param  iterable<mixed>  $candidates
     * @return list<array{id: ?int, position: int, evidence: HardwareEvidence}>
     */
    private function normalizeCandidates(iterable $candidates): array
    {
        $normalized = [];
        $position = 0;
        foreach ($candidates as $candidate) {
            $position++;
            $entry = $this->normalizeCandidate($candidate, $position);
            if ($entry !== null) {
                $normalized[] = $entry;
            }
        }

        usort($normalized, static function (array $left, array $right): int {
            if ($left['id'] !== null && $right['id'] !== null) {
                return $left['id'] <=> $right['id'];
            }
            if ($left['id'] !== null) {
                return -1;
            }
            if ($right['id'] !== null) {
                return 1;
            }

            return $left['position'] <=> $right['position'];
        });

        return $normalized;
    }

    /** @return array{id: ?int, position: int, evidence: HardwareEvidence}|null */
    private function normalizeCandidate(mixed $candidate, int $position): ?array
    {
        $id = null;
        $candidateEvidence = $candidate;

        if (is_array($candidate)) {
            $id = $this->candidateId($candidate['id'] ?? null);
            $candidateEvidence = $candidate['evidence'] ?? $candidate;
        } elseif (is_object($candidate) && ! $candidate instanceof HardwareEvidence) {
            if (method_exists($candidate, 'getKey')) {
                $id = $this->candidateId($candidate->getKey());
            } elseif (isset($candidate->id)) {
                $id = $this->candidateId($candidate->id);
            }

            if (isset($candidate->evidence) && ($candidate->evidence instanceof HardwareEvidence || is_array($candidate->evidence))) {
                $candidateEvidence = $candidate->evidence;
            } elseif ($candidate instanceof Arrayable) {
                $candidateEvidence = $candidate->toArray();
            } elseif (method_exists($candidate, 'toArray')) {
                $candidateEvidence = $candidate->toArray();
            } else {
                return null;
            }
        }

        if ($candidateEvidence instanceof HardwareEvidence) {
            $evidence = $this->normalizer->fromArray($candidateEvidence->toArray());
        } elseif (is_array($candidateEvidence)) {
            $id ??= $this->candidateId($candidateEvidence['id'] ?? null);
            $evidence = $this->normalizer->fromArray($candidateEvidence);
        } else {
            return null;
        }

        return ['id' => $id, 'position' => $position, 'evidence' => $evidence];
    }

    /**
     * @return array{
     *     id: ?int,
     *     evidence: HardwareEvidence,
     *     relevant: bool,
     *     eligible: bool,
     *     strong: bool,
     *     signals: list<string>,
     *     conflicts: list<string>
     * }
     */
    private function compare(HardwareEvidence $subject, HardwareEvidence $candidate, ?int $id, int $position): array
    {
        $label = 'candidate:'.($id ?? '#'.$position);
        $signals = [];
        $conflicts = [];
        $sharedMpns = array_values(array_intersect($subject->mpns, $candidate->mpns));
        $componentSame = $this->sameKnown($subject->componentType, $candidate->componentType);
        $manufacturerSame = $this->sameKnown($subject->manufacturer, $candidate->manufacturer);
        $modelSame = $this->sameKnown($subject->model, $candidate->model);
        $titleSame = $this->sameKnown($subject->title, $candidate->title);
        [$matchingAttributes, $differentAttributes] = $this->criticalAttributeComparison($subject, $candidate);

        foreach ($sharedMpns as $mpn) {
            $signals[] = $label.':exact_mpn:'.$mpn;
        }
        if ($componentSame) {
            $signals[] = $label.':component_type:'.$subject->componentType;
        }
        if ($manufacturerSame) {
            $signals[] = $label.':manufacturer:'.$subject->manufacturer;
        }
        if ($modelSame) {
            $signals[] = $label.':model:'.$subject->model;
        }
        foreach ($matchingAttributes as $key => $value) {
            $signals[] = $label.':attribute:'.$key.'='.$this->printable($value);
        }
        if ($titleSame) {
            $signals[] = $label.':weak:exact_title';
        }

        $hasBothMpns = $subject->mpns !== [] && $candidate->mpns !== [];
        $attributeOnlyRelation = $componentSame
            && $manufacturerSame
            && ! $this->differentKnown($subject->model, $candidate->model)
            && count($matchingAttributes) >= 2
            && $differentAttributes === [];
        $coreRelation = $sharedMpns !== []
            || $modelSame
            || $attributeOnlyRelation;
        $relevant = $coreRelation || $titleSame;

        if (($sharedMpns !== [] || $modelSame || count($matchingAttributes) >= 2)
            && $this->differentKnown($subject->componentType, $candidate->componentType)) {
            $conflicts[] = $label.':component_type:'.$subject->componentType.'!='.$candidate->componentType;
        }
        if (($sharedMpns !== [] || $modelSame || count($matchingAttributes) >= 2)
            && $this->differentKnown($subject->manufacturer, $candidate->manufacturer)) {
            $conflicts[] = $label.':manufacturer:'.$subject->manufacturer.'!='.$candidate->manufacturer;
        }
        if (($sharedMpns !== [] || ($componentSame && $manufacturerSame && $matchingAttributes !== []))
            && $this->differentKnown($subject->model, $candidate->model)) {
            $conflicts[] = $label.':model:'.$subject->model.'!='.$candidate->model;
        }
        if ($hasBothMpns && $sharedMpns === []
            && ($modelSame || ($componentSame && $manufacturerSame && $matchingAttributes !== []))) {
            $conflicts[] = $label.':mpn:no_exact_overlap';
        }
        if ($differentAttributes !== [] && ($sharedMpns !== [] || $modelSame || ($componentSame && $manufacturerSame))) {
            foreach ($differentAttributes as $key => [$left, $right]) {
                $conflicts[] = $label.':attribute:'.$key.':'.$this->printable($left).'!='.$this->printable($right);
            }
        }
        if (($sharedMpns !== [] || $modelSame) && $this->differentKnown($subject->condition, $candidate->condition)) {
            $conflicts[] = $label.':condition:'.$subject->condition.'!='.$candidate->condition;
        }
        if (($sharedMpns !== [] || $modelSame) && $subject->bundle !== $candidate->bundle) {
            $conflicts[] = $label.':bundle:'.($subject->bundle ? 'true' : 'false').'!='.($candidate->bundle ? 'true' : 'false');
        }

        if ($subject->isUnsafeForVerification()) {
            $signals[] = $label.':verification_blocked:subject_listing_risk';
        }
        if ($candidate->isUnsafeForVerification()) {
            $signals[] = $label.':verification_blocked:candidate_listing_risk';
        }

        $strong = $sharedMpns !== []
            && $componentSame
            && $manufacturerSame
            && $subject->canEstablishIdentity()
            && $candidate->canEstablishIdentity();
        $medium = ($sharedMpns !== [] && ($componentSame || $manufacturerSame))
            || ($modelSame && $componentSame && ($manufacturerSame || $matchingAttributes !== []))
            || ($componentSame && $manufacturerSame && $matchingAttributes !== [])
            || ($componentSame && count($matchingAttributes) >= 2);

        $signals = array_values(array_unique($signals));
        $conflicts = array_values(array_unique($conflicts));
        sort($signals, SORT_STRING);
        sort($conflicts, SORT_STRING);

        return [
            'id' => $id,
            'evidence' => $candidate,
            'relevant' => $relevant,
            'eligible' => $conflicts === [] && ($strong || $medium),
            'strong' => $strong,
            'signals' => $signals,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return array{array<string, bool|int|float|string>, array<string, array{bool|int|float|string, bool|int|float|string}>}
     */
    private function criticalAttributeComparison(HardwareEvidence $subject, HardwareEvidence $candidate): array
    {
        $componentType = $subject->componentType ?? $candidate->componentType;
        $keys = self::CRITICAL_ATTRIBUTES[$componentType] ?? [];
        $matching = [];
        $different = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $subject->attributes) || ! array_key_exists($key, $candidate->attributes)) {
                continue;
            }
            $left = $subject->attributes[$key];
            $right = $candidate->attributes[$key];
            if ($left === $right) {
                $matching[$key] = $left;
            } else {
                $different[$key] = [$left, $right];
            }
        }

        return [$matching, $different];
    }

    /** @param list<array{id: ?int}> $comparisons */
    private function candidateIds(array $comparisons): array
    {
        $ids = array_values(array_unique(array_filter(
            array_column($comparisons, 'id'),
            static fn (mixed $id): bool => is_int($id),
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @param list<array<string, mixed>> $comparisons */
    private function messages(array $comparisons, string $key): array
    {
        $messages = [];
        foreach ($comparisons as $comparison) {
            foreach ($comparison[$key] as $message) {
                $messages[] = $message;
            }
        }
        $messages = array_values(array_unique($messages));
        sort($messages, SORT_STRING);

        return $messages;
    }

    private function sameKnown(?string $left, ?string $right): bool
    {
        return $left !== null && $right !== null && $left === $right;
    }

    private function differentKnown(?string $left, ?string $right): bool
    {
        return $left !== null && $right !== null && $left !== $right;
    }

    private function candidateId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function printable(bool|int|float|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
