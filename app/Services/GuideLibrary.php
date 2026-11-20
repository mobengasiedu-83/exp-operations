<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishes the operating manuals that ship with the console.
 *
 * Each role has one complete manual rendered from a markdown file in `docs/`
 * and gated by its own `view … guides` permission, so the menu, the index page
 * and the controller all read the same rule: an agent never sees the viewer
 * manual, a viewer never sees the call-centre manual, and the Super
 * Administrator manual is the only one that always comes with the other two.
 */
class GuideLibrary
{
    public const AGENT = 'agent';

    public const VIEWER = 'viewer';

    public const SUPER_ADMIN = 'super-admin';

    /** Average adult reading speed used for the reading time hint. */
    private const WORDS_PER_MINUTE = 220;

    /**
     * @var array<string, array{key: string, title: string, short_title: string, audience: string, summary: string, permission: string, file: string, icon: string, accent: string}>
     */
    private const GUIDES = [
        self::AGENT => [
            'key' => self::AGENT,
            'title' => 'Agent role guide',
            'short_title' => 'Agent guide',
            'audience' => 'Agent',
            'summary' => 'The complete call-centre manual: answering the line, consent, the safety screen, SBAR handover, warm transfers, dispositions, shift handover and call history.',
            'permission' => 'view agent guides',
            'file' => 'EXP_Agent_User_Manual.md',
            'icon' => 'headphones',
            'accent' => 'emerald',
        ],
        self::VIEWER => [
            'key' => self::VIEWER,
            'title' => 'Viewer role guide',
            'short_title' => 'Viewer guide',
            'audience' => 'Viewer',
            'summary' => 'The complete read-only manual: reading the dashboard, call history and call records, dentist coverage and published reports without changing a record.',
            'permission' => 'view viewer guides',
            'file' => 'EXP_Viewer_User_Manual.md',
            'icon' => 'eye',
            'accent' => 'sky',
        ],
        self::SUPER_ADMIN => [
            'key' => self::SUPER_ADMIN,
            'title' => 'Super Administrator role guide',
            'short_title' => 'Super Administrator guide',
            'audience' => 'Super Administrator',
            'summary' => 'The complete governance manual: users, roles and permissions, service settings, PBX and integration health, questionnaires, audit, reports, and the security duties of the platform owner.',
            'permission' => 'view super admin guides',
            'file' => 'EXP_Super_Admin_User_Manual.md',
            'icon' => 'shield-check',
            'accent' => 'violet',
        ],
    ];

    /**
     * The guides the signed-in user may open.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(?User $user, ?string $except = null): array
    {
        if ($user === null) {
            return [];
        }

        $guides = [];

        foreach (array_keys(self::GUIDES) as $key) {
            if ($key === $except || ! $user->can(self::GUIDES[$key]['permission'])) {
                continue;
            }

            $guides[] = $this->describe($key);
        }

        return $guides;
    }

    /**
     * Menu entries for the Guides submenu, filtered by the same permissions.
     *
     * @return list<array{key: string, title: string, href: string, icon: string}>
     */
    public function navigation(?User $user): array
    {
        return array_map(
            fn (array $guide): array => [
                'key' => 'guide-'.$guide['key'],
                'title' => $guide['short_title'],
                'href' => $guide['href'],
                'icon' => 'book-open',
            ],
            $this->visibleTo($user),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(string $key): array
    {
        if (! isset(self::GUIDES[$key])) {
            throw new NotFoundHttpException('That guide does not exist.');
        }

        return $this->describe($key);
    }

    /**
     * Render a manual to HTML, lift its document facts out of the body and
     * collect the section headings for the "on this page" navigation.
     *
     * @return array{title: string, html: string, headings: list<array{title: string, id: string}>, facts: list<array{label: string, value: string}>, sections: int}
     */
    public function document(string $key): array
    {
        $guide = $this->meta($key);
        $path = $this->path($guide['file']);

        if (! File::exists($path)) {
            throw new NotFoundHttpException('This manual is not installed on this environment.');
        }

        $markdown = File::get($path);
        $title = $guide['title'];

        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches) === 1) {
            $title = trim($matches[1]);
            $markdown = (string) preg_replace('/^#\s+.+\n?/m', '', $markdown, 1);
        }

        // The printed table of contents is replaced by the sticky section nav.
        $markdown = (string) preg_replace('/^## Contents\s*$.*?(?=^## )/ms', '', $markdown, 1);

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 30,
        ]);

        [$markdown, $facts] = $this->extractFacts($markdown, $converter);

        $html = (string) $converter->convert($markdown);

        // The fact block leaves its separator behind; a leading rule adds nothing.
        $html = (string) preg_replace('/^(?:\s*<hr\s*\/?>)+/i', '', $html, 1);

        [$html, $headings] = $this->anchorHeadings($html);

        // A thematic break between chapters duplicates the rule the section
        // heading already draws, so drop it; breaks inside a chapter stay.
        $html = (string) preg_replace('/<hr\s*\/?>\s*(?=<h2\b)/i', '', $html);

        $html = str_replace(
            '<table>',
            '<div class="guide-table-scroll"><table class="guide-table">',
            $html,
        );
        $html = str_replace('</table>', '</table></div>', $html);

        return [
            'title' => $title,
            'html' => $html,
            'headings' => $headings,
            'facts' => $facts,
            'sections' => count($headings),
        ];
    }

    /**
     * Pull the `**Label:** value` block that follows the manual title into a
     * list of facts, so the page can present it as a proper document header
     * instead of one run-on paragraph.
     *
     * @return array{0: string, 1: list<array{label: string, value: string}>}
     */
    private function extractFacts(string $markdown, GithubFlavoredMarkdownConverter $converter): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $cursor = 0;

        while (isset($lines[$cursor]) && trim($lines[$cursor]) === '') {
            $cursor++;
        }

        $facts = [];
        $scanned = $cursor;

        while (
            isset($lines[$scanned])
            && preg_match('/^\*\*([^*]+):\*\*\s*(.+)$/', trim($lines[$scanned]), $matches) === 1
        ) {
            $inline = trim((string) $converter->convert(trim($matches[2])));
            $inline = (string) preg_replace('/^<p>|<\/p>$/i', '', $inline);

            $facts[] = ['label' => trim($matches[1]), 'value' => $inline];
            $scanned++;
        }

        if ($facts === []) {
            return [$markdown, []];
        }

        $remainder = ltrim(implode("\n", array_slice($lines, $scanned)), "\n");

        return [$remainder, $facts];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $key): array
    {
        $guide = self::GUIDES[$key];
        $path = $this->path($guide['file']);
        $available = File::exists($path);
        $markdown = $available ? File::get($path) : '';
        $words = $markdown === '' ? 0 : str_word_count($markdown);
        $sections = $markdown === '' ? 0 : (int) preg_match_all('/^## (?!Contents)\S/m', $markdown);

        return [
            ...$guide,
            'href' => route('guides.show', $key),
            'available' => $available,
            'sections' => $sections,
            'reading_minutes' => $words === 0 ? 0 : max(1, (int) ceil($words / self::WORDS_PER_MINUTE)),
            'updated_at' => $available
                ? CarbonImmutable::createFromTimestamp(File::lastModified($path))->toIso8601String()
                : null,
        ];
    }

    /**
     * Give second- and third-level headings stable ids so the sidebar can link
     * to a section, and return the second-level headings for the nav.
     *
     * @return array{0: string, 1: list<array{title: string, id: string}>}
     */
    private function anchorHeadings(string $html): array
    {
        $headings = [];
        $used = [];

        $html = (string) preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            function (array $matches) use (&$headings, &$used): string {
                $level = (int) $matches[1];
                $title = trim(html_entity_decode(strip_tags($matches[2]), ENT_QUOTES));
                $slug = Str::slug($title);
                $slug = $slug === '' ? 'section' : $slug;
                $used[$slug] = ($used[$slug] ?? 0) + 1;
                $id = $used[$slug] > 1 ? $slug.'-'.$used[$slug] : $slug;

                if ($level === 2) {
                    $headings[] = ['title' => $title, 'id' => $id];
                }

                $anchor = sprintf(
                    '<a class="guide-anchor" href="#%s" tabindex="-1" aria-hidden="true">#</a>',
                    $id,
                );

                return sprintf('<h%d id="%s">%s%s</h%d>', $level, $id, $matches[2], $anchor, $level);
            },
            $html,
        );

        return [$html, $headings];
    }

    private function path(string $file): string
    {
        return base_path('docs/'.basename($file));
    }
}
