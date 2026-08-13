<?php

namespace App\Dto;

use App\Enums\IdentityResolutionState;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class IdentityResolution implements Arrayable, JsonSerializable
{
    /**
     * @param  list<int>  $candidateIds
     * @param  list<string>  $signals
     * @param  list<string>  $conflicts
     */
    public function __construct(
        public IdentityResolutionState $state,
        public ?int $matchedIdentityId = null,
        public array $candidateIds = [],
        public array $signals = [],
        public array $conflicts = [],
        public string $reason = '',
        public string $suggestedAction = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'matched_identity_id' => $this->matchedIdentityId,
            'candidate_ids' => $this->candidateIds,
            'signals' => $this->signals,
            'conflicts' => $this->conflicts,
            'reason' => $this->reason,
            'suggested_action' => $this->suggestedAction,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
