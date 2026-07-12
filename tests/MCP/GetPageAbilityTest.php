<?php
namespace WPMCP\Tests\MCP;
use WPMCP\MCP\{Registrar, Ability};
use WPMCP\Tools\Get_Page;
class GetPageAbilityTest extends \WP_UnitTestCase {
    public function test_get_page_handler_returns_page_data(): void {
        $id = self::factory()->post->create( [ 'post_type'=>'page','post_title'=>'Home','post_content'=>'<p>hi</p>' ] );
        $out = ( new Get_Page() )->handle( [ 'id' => $id ] );
        $this->assertSame( 'Home', $out['title'] );
        $this->assertFalse( $out['is_elementor'] );
    }
    public function test_registrar_registers_free_ability(): void {
        $r = new Registrar();
        $r->register( new Ability( 'wpmcp/get-page', 'free', 'Read a page', [], fn($a) => [] ) );
        $names = array_map( fn( Ability $a ) => $a->name, $r->all() );
        $this->assertContains( 'wpmcp/get-page', $names );
    }
}
