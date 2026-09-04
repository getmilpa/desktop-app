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

namespace Milpa\DesktopApp\Tests\Live;

use Milpa\DesktopApp\Live\CommandListView;
use PHPUnit\Framework\TestCase;

/**
 * The composer's command completion is a pure view over the list the house serves (greenhouse
 * decisions/0202): one option per command, house and skill alike; nothing when there is nothing to complete.
 */
final class CommandListViewTest extends TestCase
{
    public function testItRendersOneOptionPerCommandWithItsDescriptionAndUsage(): void
    {
        $html = (new CommandListView())->html([
            ['name' => 'goal', 'kind' => 'house', 'description' => "Set, show or clear the session's standing goal", 'usage' => '/goal <text> | /goal clear | /goal', 'method' => 'POST'],
            ['name' => 'brainstorming', 'kind' => 'skill', 'description' => 'Frame the question before building', 'usage' => '/brainstorming [args]', 'method' => 'GET'],
        ]);

        self::assertStringContainsString('id="milpa-command-list"', $html);
        self::assertStringContainsString('role="listbox"', $html);
        self::assertStringContainsString('data-open="0"', $html, 'closed until the composer types a slash');
        self::assertSame(2, substr_count($html, 'role="option"'));
        // Each option has an id, so the field's aria-activedescendant can name the highlighted one.
        self::assertStringContainsString('id="milpa-cmd-goal" data-command="goal" data-kind="house"', $html);
        self::assertStringContainsString('id="milpa-cmd-brainstorming" data-command="brainstorming" data-kind="skill"', $html);
        self::assertStringContainsString('<span class="milpa-cmd__name">/goal</span>', $html);
        self::assertStringContainsString('Frame the question before building', $html);
        // The usage is escaped: the angle brackets of a placeholder never become markup.
        self::assertStringContainsString('/goal &lt;text&gt; | /goal clear | /goal', $html);
        self::assertStringNotContainsString('<text>', $html);
    }

    public function testItRendersNothingWhenThereAreNoCommands(): void
    {
        self::assertSame('', (new CommandListView())->html([]));
    }

    public function testTheJsonProjectionCannotCloseTheScriptItSitsIn(): void
    {
        // The same list as JSON for the parser: a description the house did not write — one that tries to
        // close the script and open its own — carries no `<`, `>`, `&` or quote once encoded, and still
        // decodes to the same strings. Slashes stay readable (`/goal`).
        $commands = [
            ['name' => 'evil', 'kind' => 'skill', 'description' => '<!--<script>alert("x")</script>&\'', 'usage' => '/evil [args]', 'method' => 'GET'],
        ];

        $json = CommandListView::json($commands);

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString('&', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertStringNotContainsString('\/', $json, 'slashes are not escaped');
        self::assertStringContainsString('"usage":"/evil [args]"', $json);
        self::assertSame($commands, json_decode($json, true));
    }
}
