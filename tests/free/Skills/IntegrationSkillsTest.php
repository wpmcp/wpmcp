<?php

namespace WPMCP\Tests\Free\Skills;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Skills\Skill_Library;
use WPMCP\Tools\Skills\List_Skills;

/**
 * Integration and builder skills (issue #850): `requires_any` availability,
 * so a skill is listed only while one of its plugins or builders is active,
 * and the bundled area skills themselves.
 */
class IntegrationSkillsTest extends \WP_UnitTestCase
{
    use Skill_Fixtures;

    /** @var Registrar|null the shared registrar, while a test swaps its own in. */
    private ?Registrar $original_registrar = null;

    protected function setUp(): void
    {
        parent::setUp();
        Skill_Library::reset();
    }

    protected function tearDown(): void
    {
        if (null !== $this->original_registrar) {
            $this->registrar_property()->setValue(Plugin::instance(), $this->original_registrar);
            $this->original_registrar = null;
        }
        Gate::set_pro_for_tests(null);
        $this->clean_up_skill_fixtures();
        parent::tearDown();
    }

    private function registrar_property(): \ReflectionProperty
    {
        return new \ReflectionProperty(Plugin::class, 'registrar');
    }

    /**
     * Swap a fresh registrar into the plugin holding one fake dispatcher pair
     * whose catalog lists $operations (name => dependency met).
     *
     * @param array<string, bool> $operations
     */
    private function use_fake_pair(string $name, array $operations): void
    {
        $prop = $this->registrar_property();
        if (null === $this->original_registrar) {
            $this->original_registrar = $prop->getValue(Plugin::instance());
        }

        $pair = new class ($operations) {
            /** @param array<string, bool> $ops */
            public function __construct(private array $ops)
            {
            }

            public function catalog(): array
            {
                $ops = [];
                foreach ($this->ops as $op => $met) {
                    $ops[] = [ 'name' => $op, 'dependency_met' => $met ];
                }
                return [ 'integration' => 'fake', 'available' => true, 'operations' => $ops ];
            }

            public function handle_read(array $args): array
            {
                return [];
            }
        };

        $registrar = new Registrar();
        $registrar->register(new Ability($name, 'free', 'Fake pair for the skills suite', [ 'type' => 'object' ], [ $pair, 'handle_read' ], 'read', 'skills', 'read'));
        $prop->setValue(Plugin::instance(), $registrar);
        Skill_Library::reset();
    }

    public function test_requires_any_entries_must_be_ability_or_ability_hash_operation(): void
    {
        $this->assertSame([], Skill_Library::validate([
            'name'         => 'n',
            'description'  => 'd',
            'version'      => '1.0.0',
            'requires_any' => [ 'wpmcp/plugin-data-read#tutor-list-courses', 'wpmcp/tec-read' ],
        ], 'Body'));

        $this->assertContains('invalid_requires_any', Skill_Library::validate([
            'name'         => 'n',
            'description'  => 'd',
            'version'      => '1.0.0',
            'requires_any' => [ 'tutor-list-courses' ],
        ], 'Body'));
    }

    public function test_a_skill_is_available_when_any_one_entry_is_met(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'one-of-them', $this->skill_doc(['requires_any' => '[wpmcp/no-such-ability, wpmcp/get-page]']));
        $this->write_skill($dir, 'none-of-them', $this->skill_doc(['requires_any' => '[wpmcp/no-such-ability, wpmcp/no-such-pair#no-such-op]']));
        $this->use_only_source($dir);

        $list = new List_Skills();
        $this->assertSame(['one-of-them'], array_column($list->handle([])['skills'], 'slug'));

        $all    = array_column($list->handle(['include_unavailable' => true])['skills'], null, 'slug');
        $hidden = $all['none-of-them'];
        $this->assertFalse($hidden['available']);
        $this->assertSame(['wpmcp/no-such-ability', 'wpmcp/no-such-pair#no-such-op'], $hidden['missing_any']);
        $this->assertArrayNotHasKey('missing_any', $all['one-of-them']);
    }

    public function test_an_operation_entry_needs_the_operations_own_plugin_active(): void
    {
        $this->use_fake_pair('wpmcp/fake-pair-read', [ 'fake-active-op' => true, 'fake-inactive-op' => false ]);

        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'active-plugin', $this->skill_doc(['requires_any' => '[wpmcp/fake-pair-read#fake-active-op]']));
        $this->write_skill($dir, 'inactive-plugin', $this->skill_doc(['requires_any' => '[wpmcp/fake-pair-read#fake-inactive-op]']));
        $this->write_skill($dir, 'unknown-op', $this->skill_doc(['requires_any' => '[wpmcp/fake-pair-read#not-an-op]']));
        $this->use_only_source($dir);

        $skills = array_column(Skill_Library::all(), 'available', 'slug');
        $this->assertTrue($skills['active-plugin']);
        $this->assertFalse($skills['inactive-plugin']);
        $this->assertFalse($skills['unknown-op']);
    }

    public function test_bundled_area_skills_are_hidden_while_their_plugins_are_inactive(): void
    {
        $this->use_fake_pair('wpmcp/plugin-data-read', [ 'tutor-list-courses' => false, 'learndash-list-courses' => false ]);

        $listed = array_column((new List_Skills())->handle([])['skills'], 'slug');
        $this->assertNotContains('integrations/lms', $listed);

        $all = array_column((new List_Skills())->handle(['include_unavailable' => true])['skills'], null, 'slug');
        $this->assertArrayHasKey('integrations/lms', $all);
        $this->assertFalse($all['integrations/lms']['available']);
    }

    public function test_a_bundled_area_skill_is_listed_once_one_of_its_plugins_is_active(): void
    {
        Gate::set_pro_for_tests(true);
        $this->use_fake_pair('wpmcp/plugin-data-read', [ 'tutor-list-courses' => false, 'learndash-list-courses' => true ]);

        $listed = array_column((new List_Skills())->handle([])['skills'], 'slug');
        $this->assertContains('integrations/lms', $listed);
    }

    public function test_plugin_specific_skills_are_not_published_in_public_discovery_documents(): void
    {
        foreach (\WPMCP\MCP\Discovery_Documents::published_skills() as $record) {
            $this->assertSame([], $record['requires_any'], 'A public document must not reveal which plugins the site runs.');
        }
    }
}
