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

use Milpa\Mercure\MercureService;
use Milpa\Runtime\Config;

/**
 * The Mercure hub wiring an app opts into (greenhouse decisions/0188, 0475).
 *
 * Present only when the app configured `desktop.mercure.*`: the internal hub URL the app publishes to, the
 * public URL the browser subscribes to, the publisher/subscriber HMAC keys, and the topic shell changes ride
 * on. It builds the graduated {@see MercureService} (evidence/0474) and mints the subscriber JWT the shell's
 * client presents to the hub. Absent, the app runs on the shared-log feed alone (0473) — the hub is opt-in.
 */
final class MercureConfig
{
    public function __construct(
        public readonly string $hubUrl,
        public readonly string $publicUrl,
        public readonly string $publisherKey,
        public readonly string $subscriberKey,
        public readonly string $topic,
    ) {
    }

    /** Read `desktop.mercure.*` from config; null unless the hub URL and both keys are all present. */
    public static function fromConfig(Config $config): ?self
    {
        $hubUrl = $config->get('desktop.mercure.hub_url');
        $publisherKey = $config->get('desktop.mercure.publisher_key');
        $subscriberKey = $config->get('desktop.mercure.subscriber_key');

        if (!is_string($hubUrl) || $hubUrl === '' || !is_string($publisherKey) || $publisherKey === '' || !is_string($subscriberKey) || $subscriberKey === '') {
            return null;
        }

        $publicUrl = $config->get('desktop.mercure.public_url');
        $topic = $config->get('desktop.mercure.topic');

        return new self(
            $hubUrl,
            is_string($publicUrl) && $publicUrl !== '' ? $publicUrl : $hubUrl,
            $publisherKey,
            $subscriberKey,
            is_string($topic) && $topic !== '' ? $topic : 'desktop/shell',
        );
    }

    /** The graduated Mercure client (evidence/0474), built from this wiring. */
    public function service(): MercureService
    {
        return new MercureService($this->hubUrl, $this->publicUrl, $this->publisherKey, $this->subscriberKey);
    }

    /** The exact topic a governed agent turn's `session.*` events ride, for `$id` (greenhouse decisions/0190). */
    public static function sessionTopic(string $id): string
    {
        return 'milpa/sessions/' . $id;
    }

    /**
     * A subscriber JWT scoped to the shell topic plus any `$extraTopics`, for the browser to present to the
     * hub. The shell adds the exact agent session topic ({@see sessionTopic()}) so a governed turn's
     * `session.*` events (activity/thinking, message, waiting) reach the shell on the SAME hub it already
     * reads (greenhouse decisions/0190) — no second credential. Exact topics, not a URI template: the hub
     * authorizes and delivers them without template-matching ambiguity.
     *
     * @param list<string> $extraTopics
     */
    public function subscriberJwt(array $extraTopics = []): string
    {
        return $this->service()->generateSubscriberJwt(array_merge([$this->topic], $extraTopics));
    }
}
