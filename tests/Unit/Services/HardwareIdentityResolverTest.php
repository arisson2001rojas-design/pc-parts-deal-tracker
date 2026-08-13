<?php

namespace Tests\Unit\Services;

use App\Dto\HardwareEvidence;
use App\Enums\IdentityResolutionState;
use App\Services\HardwareEvidenceNormalizer;
use App\Services\HardwareIdentityResolver;
use PHPUnit\Framework\TestCase;

class HardwareIdentityResolverTest extends TestCase
{
    private HardwareEvidenceNormalizer $normalizer;

    private HardwareIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new HardwareEvidenceNormalizer;
        $this->resolver = new HardwareIdentityResolver($this->normalizer);
    }

    public function test_exact_typed_mpn_manufacturer_and_component_resolve_verified(): void
    {
        $subject = $this->storage('8TB', 'MZ-77Q8T0B/AM');
        $candidate = $this->storage('8000GB', 'MZ 77Q8T0B AM');

        $resolution = $this->resolver->resolve($subject, [
            ['id' => 41, 'evidence' => $candidate],
        ]);

        $this->assertSame(IdentityResolutionState::Verified, $resolution->state);
        $this->assertSame(41, $resolution->matchedIdentityId);
        $this->assertSame([41], $resolution->candidateIds);
        $this->assertStringContainsString('Exact typed MPN', $resolution->reason);
        $this->assertSame('associate_identity', $resolution->suggestedAction);
    }

    public function test_same_mpn_in_another_component_type_is_conflicting(): void
    {
        $subject = $this->evidence([
            'component_type' => 'cpu',
            'manufacturer' => 'AMD',
            'model' => 'Ryzen 7 7800X3D',
            'mpn' => '100-000000910',
        ]);
        $candidate = $this->evidence([
            'component_type' => 'gpu',
            'manufacturer' => 'AMD',
            'model' => 'Radeon RX 7800 XT',
            'mpn' => '100-000000910',
        ]);

        $resolution = $this->resolver->resolve($subject, [['id' => 7, 'evidence' => $candidate]]);

        $this->assertSame(IdentityResolutionState::Conflicting, $resolution->state);
        $this->assertSame([7], $resolution->candidateIds);
        $this->assertTrue($this->containsMessage($resolution->conflicts, 'component_type'));
        $this->assertNull($resolution->matchedIdentityId);
    }

    public function test_same_storage_family_with_different_capacity_fails_closed(): void
    {
        $resolution = $this->resolver->resolve(
            $this->storage('8TB'),
            [['id' => 8, 'evidence' => $this->storage('1TB')]],
        );

        $this->assertSame(IdentityResolutionState::Conflicting, $resolution->state);
        $this->assertTrue($this->containsMessage($resolution->conflicts, 'capacity_gb'));
    }

    public function test_explicit_cpu_model_and_mpn_contradictions_fail_closed(): void
    {
        $subject = $this->evidence([
            'component_type' => 'cpu',
            'manufacturer' => 'AMD',
            'model' => 'Ryzen 7 7800X3D',
            'mpn' => 'CPU-A-12345',
        ]);
        $differentModel = $this->evidence([
            'component_type' => 'cpu',
            'manufacturer' => 'AMD',
            'model' => 'Ryzen 7 7800X',
            'mpn' => 'CPU-A-12345',
        ]);
        $differentMpn = $this->evidence([
            'component_type' => 'cpu',
            'manufacturer' => 'AMD',
            'model' => 'Ryzen 7 7800X3D',
            'mpn' => 'CPU-B-12345',
        ]);

        $modelConflict = $this->resolver->resolve($subject, [['id' => 1, 'evidence' => $differentModel]]);
        $mpnConflict = $this->resolver->resolve($subject, [['id' => 2, 'evidence' => $differentMpn]]);

        $this->assertSame(IdentityResolutionState::Conflicting, $modelConflict->state);
        $this->assertTrue($this->containsMessage($modelConflict->conflicts, ':model:'));
        $this->assertSame(IdentityResolutionState::Conflicting, $mpnConflict->state);
        $this->assertTrue($this->containsMessage($mpnConflict->conflicts, 'mpn:no_exact_overlap'));
    }

    public function test_same_gpu_family_with_different_vram_does_not_collapse(): void
    {
        $subject = $this->evidence([
            'component_type' => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model' => 'RTX 4060 Ti',
            'vram_gb' => 8,
        ]);
        $candidate = $this->evidence([
            'component_type' => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model' => 'RTX 4060 Ti',
            'vram_gb' => 16,
        ]);

        $resolution = $this->resolver->resolve($subject, [['id' => 22, 'evidence' => $candidate]]);

        $this->assertSame(IdentityResolutionState::Conflicting, $resolution->state);
        $this->assertTrue($this->containsMessage($resolution->conflicts, 'vram_gb'));
        $this->assertNull($resolution->matchedIdentityId);
    }

    public function test_unsafe_exact_match_is_only_probable(): void
    {
        $subject = $this->evidence([
            'component_type' => 'gpu',
            'manufacturer' => 'ASUS',
            'model' => 'RTX 4070 Ti SUPER',
            'mpn' => 'TUF-RTX4070TIS-O16G',
            'condition' => 'renewed',
            'marketplace' => true,
        ]);
        $candidate = $this->evidence([
            'component_type' => 'gpu',
            'manufacturer' => 'ASUS',
            'model' => 'RTX 4070 Ti SUPER',
            'mpn' => 'TUF-RTX4070TIS-O16G',
            'condition' => 'renewed',
            'marketplace' => true,
        ]);

        $resolution = $this->resolver->resolve($subject, [['id' => 9, 'evidence' => $candidate]]);

        $this->assertSame(IdentityResolutionState::Probable, $resolution->state);
        $this->assertNull($resolution->matchedIdentityId);
        $this->assertSame('review_candidate', $resolution->suggestedAction);
    }

    public function test_exact_title_alone_remains_unverified(): void
    {
        $subject = $this->evidence(['title' => 'ACME Ultimate PC Component']);
        $candidate = $this->evidence(['title' => 'ACME Ultimate PC Component']);

        $resolution = $this->resolver->resolve($subject, [['id' => 3, 'evidence' => $candidate]]);

        $this->assertSame(IdentityResolutionState::Unverified, $resolution->state);
        $this->assertSame([3], $resolution->candidateIds);
        $this->assertTrue($this->containsMessage($resolution->signals, 'weak:exact_title'));
    }

    public function test_unrelated_candidate_is_ignored_and_authoritative_subject_can_create_an_identity(): void
    {
        $subject = $this->evidence([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '870 QVO',
            'mpn' => 'MZ-77Q8T0B/AM',
            'capacity' => '8TB',
        ]);
        $unrelated = $this->evidence([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '990 PRO',
            'mpn' => 'MZ-V9P2T0B/AM',
            'capacity' => '2TB',
        ]);

        $resolution = $this->resolver->resolve($subject, [['id' => 99, 'evidence' => $unrelated]]);

        $this->assertSame(IdentityResolutionState::Verified, $resolution->state);
        $this->assertSame([], $resolution->candidateIds);
        $this->assertSame([], $resolution->conflicts);
        $this->assertSame('create_identity', $resolution->suggestedAction);
    }

    public function test_one_medium_candidate_is_probable_and_more_than_one_is_ambiguous(): void
    {
        $subject = $this->evidence([
            'component_type' => 'psu',
            'manufacturer' => 'Corsair',
            'model' => 'RM850x',
            'wattage' => 850,
        ]);
        $candidate = $this->evidence([
            'component_type' => 'psu',
            'manufacturer' => 'Corsair',
            'model' => 'RM850x',
            'wattage' => 850,
        ]);

        $probable = $this->resolver->resolve($subject, [['id' => 12, 'evidence' => $candidate]]);
        $ambiguous = $this->resolver->resolve($subject, [
            ['id' => 12, 'evidence' => $candidate],
            ['id' => 13, 'evidence' => $candidate],
        ]);

        $this->assertSame(IdentityResolutionState::Probable, $probable->state);
        $this->assertSame([12], $probable->candidateIds);
        $this->assertSame(IdentityResolutionState::Ambiguous, $ambiguous->state);
        $this->assertSame([12, 13], $ambiguous->candidateIds);
        $this->assertSame('review_candidates', $ambiguous->suggestedAction);
    }

    public function test_resolution_serialization_contains_the_audit_trace(): void
    {
        $resolution = $this->resolver->resolve(
            $this->storage('8TB', 'MZ-77Q8T0B/AM'),
            [['id' => 41, 'evidence' => $this->storage('8TB', 'MZ-77Q8T0B/AM')]],
        );
        $serialized = $resolution->toArray();

        $this->assertSame('verified', $serialized['state']);
        $this->assertSame(41, $serialized['matched_identity_id']);
        $this->assertNotEmpty($serialized['signals']);
        $this->assertSame([], $serialized['conflicts']);
        $this->assertNotSame('', $serialized['reason']);
        $this->assertNotSame('', $serialized['suggested_action']);
    }

    private function storage(string $capacity, ?string $mpn = null): HardwareEvidence
    {
        return $this->evidence([
            'component_type' => 'ssd',
            'manufacturer' => 'Samsung',
            'model' => '870 QVO',
            'mpn' => $mpn,
            'capacity' => $capacity,
            'storage_type' => 'SSD',
            'interface' => 'SATA III',
            'form_factor' => '2.5 inch',
        ]);
    }

    /** @param array<string, mixed> $input */
    private function evidence(array $input): HardwareEvidence
    {
        return $this->normalizer->fromArray($input);
    }

    /** @param list<string> $messages */
    private function containsMessage(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
