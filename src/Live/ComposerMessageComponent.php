<?php

/**
 * This file is part of milpa/desktop-app — a Milpa app hosts itself as a desktop app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/desktop-app
 */

declare(strict_types=1);

namespace Milpa\DesktopApp\Live;

use Milpa\Live\Components\Form\AbstractFieldComponent;
use Milpa\Live\Effects\RenderEffect;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;

/**
 * The composer's message field, as a milpa/live textarea that validates ON THE SERVER and re-paints a
 * SIBLING status component (greenhouse decisions/0189, evidence/0491). This is the end-to-end demo of
 * declarative cross-component reactivity inside the Desktop: on blur, the field posts to `/desktop/live`;
 * this handler validates and returns a {@see RenderEffect} that re-paints `composer-status` — no imperative
 * JS wires the two together.
 *
 * Its contract name is `textarea`, so {@see \Milpa\Live\Rendering\FormPrimitiveHtmlRenderer} renders it as a
 * multiline field exactly like the stock textarea; only `handle('blur')` is extended.
 */
final class ComposerMessageComponent extends AbstractFieldComponent
{
    public const string STATUS_TARGET = 'composer-status';

    /** The component contract — named `textarea` so the form renderer treats it as a multiline field. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'textarea',
            contractVersion: '0.3.0-candidate',
            summary: "The Desktop composer's message field — validates on blur and re-paints the status.",
            designContract: '@milpa/design:primitives/milpa-textarea.contract.json',
            defaultTemplate: 'components/textarea.latte',
            propsSchema: [
                'name' => ['type' => 'string', 'required' => true],
                'value' => ['type' => 'string', 'default' => ''],
                'placeholder' => ['type' => 'string', 'required' => false],
                'rows' => ['type' => 'integer', 'default' => 2],
                'error' => ['type' => 'string|null', 'required' => false],
            ],
            stateSchema: [
                'value' => ['type' => 'string'],
                'dirty' => ['type' => 'boolean'],
                'touched' => ['type' => 'boolean'],
                'error' => ['type' => 'string|null'],
            ],
            actions: [
                'change' => ['payload' => ['value' => 'string']],
                'blur' => ['payload' => []],
                'reset' => ['payload' => []],
                'set-error' => ['payload' => ['error' => 'string|null']],
            ],
        );
    }

    protected function meta(array $props): array
    {
        return [
            'placeholder' => (string) ($props['placeholder'] ?? ''),
            'rows' => max(1, (int) ($props['rows'] ?? 2)),
        ];
    }

    /** Delegate to the base field behaviour, then on blur validate on the server and declare the status repaint. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $result = parent::handle($request);
        if ($request->action !== 'blur') {
            return $result;
        }

        // On blur: validate the value on the SERVER, and DECLARE that the sibling status component re-paints.
        // The current value arrives in the payload (the client sends what the user typed); the state envelope
        // may still hold the mounted value.
        $value = trim((string) ($request->payload['value'] ?? $result->state->data['value'] ?? ''));
        $errors = $value === '' ? ['value' => 'Write something before sending.'] : [];
        $status = $value === ''
            ? ['name' => 'status', 'value' => 'Empty — write a message.', 'disabled' => true]
            : ['name' => 'status', 'value' => mb_strlen($value) . ' chars · ready to send', 'disabled' => true];

        return new InteractionResult(
            state: $result->state,
            effects: array_merge($result->effects, [
                (new RenderEffect(target: self::STATUS_TARGET, component: 'input', props: $status))->toArray(),
            ]),
            errors: array_merge($result->errors, $errors),
        );
    }
}
