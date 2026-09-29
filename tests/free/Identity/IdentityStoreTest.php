<?php

namespace WPMCP\Tests\Free\Identity;

use WPMCP\Identity\Identity_Store;

class IdentityStoreTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Identity_Store::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(Identity_Store::OPTION);
        parent::tearDown();
    }

    public function test_create_persists_an_identity_and_get_retrieves_it_by_name(): void
    {
        Identity_Store::create('editor-bot', ['domains' => ['content']]);

        $identity = Identity_Store::get('editor-bot');

        $this->assertNotNull($identity);
        $this->assertSame('editor-bot', $identity['name']);
        $this->assertSame(['content'], $identity['domains']);
    }

    public function test_get_returns_null_for_an_unknown_identity(): void
    {
        $this->assertNull(Identity_Store::get('does-not-exist'));
    }

    public function test_create_defaults_missing_scope_arrays_to_empty_and_mode_to_allow(): void
    {
        Identity_Store::create('unrestricted-bot', []);

        $identity = Identity_Store::get('unrestricted-bot');

        $this->assertSame([], $identity['domains']);
        $this->assertSame([], $identity['operations']);
        $this->assertSame([], $identity['abilities']);
        $this->assertSame('allow', $identity['mode']);
    }

    public function test_list_returns_all_created_identities(): void
    {
        Identity_Store::create('bot-a', []);
        Identity_Store::create('bot-b', []);

        $names = array_column(Identity_Store::list(), 'name');

        $this->assertContains('bot-a', $names);
        $this->assertContains('bot-b', $names);
    }

    public function test_delete_removes_an_identity(): void
    {
        Identity_Store::create('temp-bot', []);

        $deleted = Identity_Store::delete('temp-bot');

        $this->assertTrue($deleted);
        $this->assertNull(Identity_Store::get('temp-bot'));
    }

    public function test_delete_returns_false_for_an_unknown_identity(): void
    {
        $this->assertFalse(Identity_Store::delete('does-not-exist'));
    }

    public function test_create_with_the_same_name_overwrites_the_existing_identity(): void
    {
        Identity_Store::create('editor-bot', ['domains' => ['content']]);
        Identity_Store::create('editor-bot', ['domains' => ['database']]);

        $identity = Identity_Store::get('editor-bot');

        $this->assertSame(['database'], $identity['domains']);
        $this->assertCount(1, Identity_Store::list());
    }

    public function test_normalize_is_the_shape_create_stores(): void
    {
        $fields = ['domains' => ['content', 7, ['x']], 'operations' => 'read', 'mode' => 'root', 'exposure' => 'compact', 'token' => 'secret'];

        $normalized = Identity_Store::normalize(2024, $fields);

        $this->assertSame(Identity_Store::create('2024', $fields), $normalized);
        $this->assertSame(['name', 'domains', 'operations', 'abilities', 'mode', 'exposure', 'allowed_ips'], array_keys($normalized));
        $this->assertSame('2024', $normalized['name']);
        $this->assertSame(['content', '7'], $normalized['domains']);
        $this->assertSame([], $normalized['operations']);
        $this->assertSame('allow', $normalized['mode']);
    }
}
