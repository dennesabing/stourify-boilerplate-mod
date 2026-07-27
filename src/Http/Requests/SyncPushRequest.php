<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Stourify\Support\Sync\SyncRegistry;

/**
 * Validates the push envelope shape only — one key per pushable
 * `SyncRegistry` table, each an object with optional `created`/`updated`
 * (arrays of row objects) and `deleted` (an array of uuids).
 *
 * Per-row *field* validation is delegated to the same FormRequest each table
 * already uses for a single API write (`SpotStoreRequest`, …) — see
 * `SyncController::pushUpsert()`. Doing that here as a nested rule set would
 * either duplicate those rules or need Laravel's `Rule::forEach`, which
 * cannot select store-vs-update rules per row (that decision depends on
 * whether the uuid already exists, a DB lookup this request has no reason to
 * make twice).
 *
 * A non-pushable table (`sto_cities`, reference data) is explicitly
 * `prohibited` rather than merely undeclared, so sending it comes back as a
 * named 422 instead of being silently ignored.
 */
class SyncPushRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (SyncRegistry::tables() as $table) {
            if (! SyncRegistry::isPushable($table)) {
                $rules[$table] = ['prohibited'];

                continue;
            }

            $rules["{$table}"] = ['sometimes', 'array'];
            $rules["{$table}.created"] = ['sometimes', 'array'];
            $rules["{$table}.created.*"] = ['array'];
            $rules["{$table}.updated"] = ['sometimes', 'array'];
            $rules["{$table}.updated.*"] = ['array'];
            $rules["{$table}.deleted"] = ['sometimes', 'array'];
            $rules["{$table}.deleted.*"] = ['string'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sto_cities.prohibited' => 'Cities are reference data and cannot be pushed.',
        ];
    }
}
