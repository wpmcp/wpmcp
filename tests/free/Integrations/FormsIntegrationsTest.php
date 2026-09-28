<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Contact_Form_7_Integration;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * The Contact Form 7 form operations, exercised against a faithful double of
 * CF7's WPCF7_ContactForm model (see tests/support/forms-stubs.php) and
 * against the real plugin in CI's live forms job (ContactForm7LiveTest). The
 * Formidable and WPForms adapters moved to the adapter pack and are tested in
 * tests/pro/Integrations.
 */
class FormsIntegrationsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \WPCF7_ContactForm::$registry = [];
        \WPCF7_ContactForm::seed(7, 'Contact', 'contact-us', [ 'form' => '[text your-name]', 'mail' => [ 'subject' => 'New message' ] ]);
    }

    // ---- Contact Form 7 ----------------------------------------------------
    public function test_cf7_lists_and_reads_forms_with_markup_and_mail(): void
    {
        $i = new Contact_Form_7_Integration();
        $this->assertTrue($i->is_available());

        $forms = $i->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(1, $forms['result']['total']);
        $this->assertSame('Contact', $forms['result']['forms'][0]['title']);

        $form = $i->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 7 ] ]);
        $this->assertSame('contact-us', $form['result']['form']['name']);
        $this->assertSame('[text your-name]', $form['result']['form']['form_markup']);
        $this->assertSame('New message', $form['result']['form']['mail']['subject']);

        $missing = $i->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 404 ] ]);
        $this->assertNull($missing['result']['form']);
    }

    public function test_cf7_lists_fields_parsed_from_the_markup(): void
    {
        \WPCF7_ContactForm::seed(8, 'Quote', 'quote', [ 'form' => '[text* your-name] [select topic "Sales" "Support"] [submit "Send"]' ]);
        $out = (new Contact_Form_7_Integration())->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => 8 ] ]);

        $this->assertSame([
            [ 'name' => 'your-name', 'type' => 'text', 'required' => true, 'options' => [] ],
            [ 'name' => 'topic', 'type' => 'select', 'required' => false, 'options' => [ 'Sales', 'Support' ] ],
        ], $out['result']['fields'], 'Unnamed tags such as submit carry no data and are left out');
        $this->assertNull((new Contact_Form_7_Integration())->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => 404 ] ])['result']['fields']);
    }

    public function test_cf7_lists_mail_and_mail_2_as_notifications(): void
    {
        \WPCF7_ContactForm::seed(9, 'Quote', 'quote', [
            'mail'   => [ 'recipient' => 'admin@example.test', 'subject' => 'New quote', 'sender' => 'Site <wp@example.test>', 'body' => '[your-name]' ],
            'mail_2' => [ 'active' => false, 'recipient' => '[your-email]', 'subject' => 'Thanks' ],
        ]);
        $notes = (new Contact_Form_7_Integration())->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => 9 ] ])['result']['notifications'];

        $this->assertSame('mail', $notes[0]['id']);
        $this->assertTrue($notes[0]['active'], 'The primary Mail template always sends');
        $this->assertSame('admin@example.test', $notes[0]['recipient']);
        $this->assertSame('mail_2', $notes[1]['id']);
        $this->assertFalse($notes[1]['active']);
    }
}
