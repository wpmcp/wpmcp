<?php
/**
 * Faithful global test double for the Ninja Forms integration, reproducing the
 * Ninja_Forms()->form() model accessor surface the integration calls (verified
 * against Ninja Forms 3.x): get_forms(), get_form(), get_fields(),
 * get_actions() and get_sub(). Submissions are REAL nf_sub posts carrying
 * Ninja Forms' own _form_id / _seq_num / _field_{id} meta, so the WP_Query
 * paging and the snapshot-backed status change run for real. Call
 * NF_Test_Sub::register() in setUp to register the post type.
 *
 * Not reproduced: the real get_field_values() also returns the extra
 * (non-field) values and keys fields by their field key where one is set;
 * this double returns the _field_{id} values only. Real Ninja_Forms() always
 * wins.
 */

class NF_Test_Field
{
    public function __construct(private int $id, private array $settings)
    {
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_setting($key)
    {
        return $this->settings[$key] ?? '';
    }
}

class NF_Test_Form
{
    public function __construct(private int $id, private array $settings, private array $fields = [])
    {
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_setting($key)
    {
        return $this->settings[$key] ?? '';
    }

    /** @return NF_Test_Field[] */
    public function get_fields(): array
    {
        return $this->fields;
    }
}

/** A Ninja Forms action model (get_id / get_setting), e.g. an email action. */
class NF_Test_Action extends NF_Test_Field
{
}

/** Double of NF_Database_Models_Submission, read from a real nf_sub post. */
class NF_Test_Sub
{
    private int $id;
    private int $form_id;
    private string $status = '';
    private string $sub_date = '';
    private int $seq_num = 0;

    public static function register(): void
    {
        register_post_type('nf_sub', [ 'public' => false, 'label' => 'Submissions' ]);
    }

    /** Test seam: create one submission the way Ninja Forms saves one. */
    public static function seed(int $form_id, int $seq_num, array $values, string $date = '2026-01-01 00:00:00', string $status = 'publish'): int
    {
        $id = wp_insert_post([ 'post_type' => 'nf_sub', 'post_status' => $status, 'post_date' => $date, 'post_title' => '' ]);
        update_post_meta($id, '_form_id', $form_id);
        update_post_meta($id, '_seq_num', $seq_num);
        foreach ($values as $field_id => $value) {
            update_post_meta($id, '_field_' . $field_id, $value);
        }
        return (int) $id;
    }

    public function __construct($id = '', $form_id = '')
    {
        $this->id      = (int) $id;
        $this->form_id = (int) $form_id;
        $post          = $this->id ? get_post($this->id) : null;
        if ($post) {
            $this->status   = (string) $post->post_status;
            $this->sub_date = (string) $post->post_date;
        }
        if ($this->id && ! $this->form_id) {
            $this->form_id = (int) get_post_meta($this->id, '_form_id', true);
        }
        if ($this->id && $this->form_id) {
            $this->seq_num = (int) get_post_meta($this->id, '_seq_num', true);
        }
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function get_form_id()
    {
        return $this->form_id;
    }

    public function get_seq_num()
    {
        return $this->seq_num;
    }

    public function get_sub_date($format = 'm/d/Y')
    {
        return gmdate($format, (int) strtotime($this->sub_date));
    }

    public function get_field_values()
    {
        $out = [];
        foreach ((array) get_post_meta($this->id) as $key => $values) {
            if (0 === strpos((string) $key, '_field_')) {
                $out[ $key ] = maybe_unserialize($values[0] ?? '');
            }
        }
        return $out;
    }
}

class NF_Test_FormHandler
{
    /** @var array<int,NF_Test_Form> */
    public static array $forms = [];

    /** @var array<int,NF_Test_Action[]> form id => actions */
    public static array $actions = [];

    private ?int $current;

    public function __construct(?int $id = null)
    {
        $this->current = $id;
    }

    /** @return NF_Test_Form[] */
    public function get_forms(): array
    {
        return array_values(self::$forms);
    }

    public function get_form(): ?NF_Test_Form
    {
        return self::$forms[(int) $this->current] ?? null;
    }

    /** @return NF_Test_Field[] */
    public function get_fields(): array
    {
        $form = $this->get_form();
        return $form ? $form->get_fields() : [];
    }

    /** @return NF_Test_Action[] */
    public function get_actions(): array
    {
        return self::$actions[(int) $this->current] ?? [];
    }

    public function get_sub($id): NF_Test_Sub
    {
        return new NF_Test_Sub($id, (int) $this->current);
    }
}

class NF_Test_Container
{
    public function form(?int $id = null): NF_Test_FormHandler
    {
        return new NF_Test_FormHandler($id);
    }
}

if (! function_exists('Ninja_Forms')) {
    function Ninja_Forms(): NF_Test_Container
    {
        return new NF_Test_Container();
    }
}
