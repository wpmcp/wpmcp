<?php

namespace WPMCP\Tests\Free\Skills;

use WPMCP\Pro\Gate;
use WPMCP\Skills\Skill_Library;
use WPMCP\Tools\Skills\Get_Skill;
use WPMCP\Tools\Skills\List_Skills;

/**
 * The two MCP tools of issue #74: list-skills (compact catalog, no bodies)
 * and get-skill (exact body, structured errors).
 */
class SkillToolsTest extends \WP_UnitTestCase
{
    use Skill_Fixtures;

    private List_Skills $list;
    private Get_Skill $get;

    protected function setUp(): void
    {
        parent::setUp();
        Skill_Library::reset();
        $this->list = new List_Skills();
        $this->get  = new Get_Skill();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        $this->clean_up_skill_fixtures();
        parent::tearDown();
    }

    public function test_list_returns_the_bundled_catalog_without_bodies(): void
    {
        $result = $this->list->handle([]);

        $this->assertSame(count($result['skills']), $result['count']);
        $this->assertNotEmpty($result['skills']);

        foreach ($result['skills'] as $skill) {
            $this->assertArrayNotHasKey('body', $skill, 'list-skills must never carry bodies.');
            $this->assertArrayHasKey('version', $skill);
            $this->assertArrayHasKey('tags', $skill);
        }

        $slugs = array_column($result['skills'], 'slug');
        $this->assertContains('wpmcp-safe-writes', $slugs);
    }

    public function test_list_stays_cheap_enough_to_call_on_every_connection(): void
    {
        $bytes = strlen((string) wp_json_encode($this->list->handle([])));

        $this->assertLessThan(
            4096,
            $bytes,
            'The whole point of splitting list-skills from get-skill is that discovery is cheap.'
        );
    }

    public function test_search_filters_the_catalog(): void
    {
        $result = $this->list->handle(['search' => 'rollback']);

        $slugs = array_column($result['skills'], 'slug');
        $this->assertContains('wpmcp-safe-writes', $slugs);
        $this->assertNotContains('wpmcp-woocommerce-catalog', $slugs);
    }

    public function test_a_search_miss_returns_the_full_catalog_with_a_note(): void
    {
        $result = $this->list->handle(['search' => 'zzz-nothing-matches-this']);

        $this->assertNotEmpty($result['skills']);
        $this->assertStringContainsString('No skill matched', $result['note']);
        $this->assertStringContainsString('wpmcp/get-skill', $result['note']);
    }

    public function test_tag_filter_selects_by_frontmatter_tag(): void
    {
        $result = $this->list->handle(['tag' => 'woocommerce']);

        $this->assertSame(['wpmcp-woocommerce-catalog'], array_column($result['skills'], 'slug'));
    }

    public function test_a_tag_that_matches_nothing_returns_an_empty_catalog(): void
    {
        $result = $this->list->handle(['tag' => 'no-such-tag']);

        $this->assertSame([], $result['skills']);
        $this->assertSame(0, $result['count']);
    }

    public function test_skills_whose_tools_are_absent_are_hidden_until_asked_for(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'runnable', $this->skill_doc(['requires' => '[wpmcp/get-page]']));
        $this->write_skill($dir, 'not-runnable', $this->skill_doc(['requires' => '[wpmcp/no-such-ability]']));
        $this->use_only_source($dir);

        $default = array_column($this->list->handle([])['skills'], 'slug');
        $this->assertSame(['runnable'], $default);

        $everything = $this->list->handle(['include_unavailable' => true])['skills'];
        $this->assertSame(['not-runnable', 'runnable'], array_column($everything, 'slug'));

        $hidden = $everything[0];
        $this->assertFalse($hidden['available']);
        $this->assertSame(['wpmcp/no-such-ability'], $hidden['missing_abilities']);
    }

    public function test_get_returns_the_full_body_and_metadata(): void
    {
        $skill = $this->get->handle(['slug' => 'wpmcp-governance']);

        $this->assertSame('wpmcp-governance', $skill['slug']);
        $this->assertSame('bundled', $skill['source']);
        $this->assertArrayNotHasKey('locked', $skill);
        $this->assertStringContainsString('Six layers, all narrowing', $skill['body']);
    }

    public function test_get_returns_the_body_byte_for_byte(): void
    {
        $dir  = $this->make_source_dir();
        $body = "Line one.\n\n  indented line, trailing spaces   \n\n- a list item\n";
        $this->write_skill($dir, 'verbatim', $this->skill_doc([], $body));
        $this->use_only_source($dir);

        $this->assertSame(trim($body), $this->get->handle(['slug' => 'verbatim'])['body']);
    }

    public function test_an_unknown_slug_returns_a_structured_error_naming_the_known_slugs(): void
    {
        $error = $this->get->handle(['slug' => 'no-such-skill']);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('wpmcp_skill_not_found', $error->get_error_code());

        $data = $error->get_error_data();
        $this->assertSame('no-such-skill', $data['slug']);
        $this->assertContains('wpmcp-safe-writes', $data['available_slugs']);
        $this->assertLessThanOrEqual(Get_Skill::MAX_SUGGESTIONS, count($data['available_slugs']));
    }

    public function test_a_traversal_shaped_slug_is_just_an_unknown_slug(): void
    {
        $error = $this->get->handle(['slug' => '../../../wp-config.php']);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('wpmcp_skill_not_found', $error->get_error_code());
    }

    public function test_a_missing_slug_argument_is_its_own_error(): void
    {
        foreach ([[], ['slug' => '   '], ['slug' => 42]] as $args) {
            $error = $this->get->handle($args);
            $this->assertInstanceOf(\WP_Error::class, $error);
            $this->assertSame('wpmcp_skill_slug_required', $error->get_error_code());
        }
    }

    public function test_a_pro_skill_is_listed_but_its_body_needs_a_licence(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'premium-playbook', $this->skill_doc(['tier' => 'pro']));
        $this->use_only_source($dir);

        $listed = $this->list->handle([])['skills'][0];
        $this->assertSame('premium-playbook', $listed['slug']);
        $this->assertTrue($listed['locked'], 'A pro skill stays discoverable; only its body is withheld.');

        $error = $this->get->handle(['slug' => 'premium-playbook']);
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('wpmcp_skill_locked', $error->get_error_code());
        $this->assertSame('pro', $error->get_error_data()['tier']);

        $message = $error->get_error_message();
        $this->assertStringContainsString('WP MCP Pro', $message);
        $this->assertStringContainsString('https://wpmcp-pro.com/pricing.html', $message);
        $this->assertStringContainsString('utm_medium=tool-refusal', $message);
        $this->assertStringContainsString('WPMCP20', $message);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $message, 'refusal copy carries a dash');
    }

    public function test_a_licensed_site_gets_the_pro_skill_body(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'premium-playbook', $this->skill_doc(['tier' => 'pro'], 'Premium body.'));
        $this->use_only_source($dir);

        Gate::set_pro_for_tests(true);
        Skill_Library::reset();

        $skill = $this->get->handle(['slug' => 'premium-playbook']);

        $this->assertSame('Premium body.', $skill['body']);
        $this->assertArrayNotHasKey('locked', $skill);
        $this->assertArrayNotHasKey('locked', $this->list->handle([])['skills'][0]);
    }
}
