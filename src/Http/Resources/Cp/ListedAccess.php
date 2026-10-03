<?php

namespace Goldnead\StatamicProducts\Http\Resources\Cp;

use Goldnead\StatamicProducts\Models\Access;
use Goldnead\StatamicProducts\Support\ContentKinds;
use Goldnead\StatamicProducts\Support\RefTarget;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ein Zugang als Zeile, und die Werte fuer sein Formular.
 *
 * @mixin Access
 */
class ListedAccess extends JsonResource
{
    public function toArray($request)
    {
        $targets = self::targets($this->resource);
        $lines = $this->creditLines();

        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'show_url' => cp_route('utilities.product-accesses.show', $this->id),
            'contents_count' => count($this->contentItems()) ?: null,
            'credits_count' => count($lines) ?: null,
            // Was die Spalte „Guthaben" zeigt: je Zeile Anzahl und Sessiontyp,
            // beendete Zeilen nicht. Kurz, weil die Zelle eine Zeile hat.
            'credits_summary' => implode(' · ', array_map(
                fn (array $line) => ($line['kind'] === Access::CREDIT_SUBSCRIPTION
                    ? __('statamic-products::messages.credit_summary_subscription', ['count' => $line['per_month']])
                    : trans_choice('statamic-products::messages.credit_summary_one_time', (int) $line['count'], ['count' => $line['count']])),
                array_values(array_filter($lines, fn (array $line) => ($line['ended_at'] ?? null) === null)),
            )),
            'members' => $this->opens_members_area,
            'active' => $this->active,
            // Ein Inhalt, dessen Ziel fehlt: bezahlt und nichts dahinter. Nur
            // „fehlt" zaehlt, „nicht pruefbar" ist kein Fehler.
            'contents_missing' => collect($targets)->contains(fn (array $t) => $t['state'] === RefTarget::MISSING),
            'targets' => $targets,
            'edit_values' => [
                'name' => $this->name,
                'handle' => $this->handle,
                'description' => $this->description,
                'cover' => $this->cover,
                'active' => $this->active,
                'opens_members_area' => $this->opens_members_area,
                'contents' => $this->contentItems(),
                // `ended` fuer den Schalter im Formular; gespeichert wird das Datum.
                'credits' => array_map(fn (array $line) => [
                    'line' => $line['line'],
                    'session_type' => $line['session_type'] ?? '',
                    'kind' => $line['kind'] ?? Access::CREDIT_ONE_TIME,
                    'count' => $line['count'] ?? null,
                    'per_month' => $line['per_month'] ?? null,
                    'valid_months' => $line['valid_months'] ?? null,
                    'ended' => ($line['ended_at'] ?? null) !== null,
                ], $lines),
            ],
        ];
    }

    /**
     * Was jeder Inhalt aufloest, nach `kind|ref`.
     *
     * Nach Schluessel statt nach Position: verschiebt jemand im Formular eine
     * Zeile, gehoert die Auskunft weiter zu ihrem Verweis.
     *
     * @return array<string, array{state: string, label: string|null}>
     */
    public static function targets(Access $access): array
    {
        $targets = [];

        foreach ($access->contentItems() as $item) {
            $target = RefTarget::forContent($item['kind'], $item['ref']);

            $targets[$item['kind'].'|'.$item['ref']] = [
                'state' => $target->state,
                'label' => $target->label,
                'kind_label' => ContentKinds::label($item['kind']),
            ];
        }

        return $targets;
    }

    public static function hasMissingContent(Access $access): bool
    {
        foreach ($access->contentItems() as $item) {
            if (RefTarget::forContent($item['kind'], $item['ref'])->isMissing()) {
                return true;
            }
        }

        return false;
    }
}
