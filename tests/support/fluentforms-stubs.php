<?php
/**
 * Faithful global test double for the Fluent Forms integration, reproducing the
 * wpFluent() query-builder surface the integration calls (verified against
 * Fluent Forms 6.x's WPFluent builder): table(), where($col, $value),
 * where($col, $op, $value), orderBy(), offset(), limit(), get(), first(),
 * count() and update().
 *
 * The builder runs against REAL tables with Fluent Forms' own names and the
 * columns the adapter touches (fluentform_forms, fluentform_form_meta,
 * fluentform_submissions), so the status write's db_rows snapshot and rollback
 * are exercised against real rows. FF_Test_DB::install() is DDL: call it from
 * set_up_before_class, never inside a test transaction. Rows come back as
 * stdClass objects, as the real builder returns them, and get() wraps them in
 * a collection OBJECT (FF_Test_Collection), as the real builder's get() does
 * (FluentForm\Framework\Support\Collection, items in a protected property):
 * an (array) cast over it yields its properties, not its rows (issue #945).
 *
 * Not reproduced: the rest of the builder (joins, whereIn, grouped wheres) and
 * Fluent Forms' Eloquent-style models. Real wpFluent() always wins.
 */

/**
 * The surface of Fluent Forms 6.x's FluentForm\Framework\Support\Collection
 * that a caller can lean on: iteration, count(), offset access and all(). The
 * rows sit in a protected property, as in the real class, so casting the
 * collection to an array does NOT give the rows.
 */
class FF_Test_Collection implements ArrayAccess, Countable, IteratorAggregate
{
    /** @var object[] */
    protected array $items;

    /** Present on the real collection; here so a cast sees the same shape. */
    protected bool $escapeWhenCastingToString = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- the real class's property name.

    public function __construct(array $items)
    {
        $this->items = array_values($items);
    }

    /** @return object[] */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->items[ $offset ]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->items[ $offset ];
    }

    public function offsetSet($offset, $value): void
    {
        null === $offset ? $this->items[] = $value : $this->items[ $offset ] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->items[ $offset ]);
    }
}

class FF_Test_Query
{
    /** @var array<int,array{0:string,1:string,2:mixed}> */
    private array $wheres = [];
    private string $order = '';
    private ?int $offset = null;
    private ?int $limit = null;

    public function __construct(private string $table)
    {
    }

    public function where(string $col, $op, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $op;
            $op    = '=';
        }
        $this->wheres[] = [ $col, in_array($op, [ '=', '!=' ], true) ? $op : '=', $value ];
        return $this;
    }

    public function orderBy(string $col, string $dir = 'ASC'): self
    {
        $this->order = sprintf(' ORDER BY `%s` %s', preg_replace('/[^a-z_]/', '', $col), 'DESC' === strtoupper($dir) ? 'DESC' : 'ASC');
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    private function where_sql(): string
    {
        global $wpdb;
        if ([] === $this->wheres) {
            return '';
        }
        $parts = [];
        foreach ($this->wheres as [ $col, $op, $value ]) {
            $parts[] = $wpdb->prepare("%i {$op} %s", $col, $value);
        }
        return ' WHERE ' . implode(' AND ', $parts);
    }

    private function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . $this->table;
    }

    public function get(): FF_Test_Collection
    {
        global $wpdb;
        $sql = $wpdb->prepare('SELECT * FROM %i', $this->table_name()) . $this->where_sql() . $this->order;
        if (null !== $this->limit) {
            $sql .= $wpdb->prepare(' LIMIT %d, %d', (int) $this->offset, $this->limit);
        }
        return new FF_Test_Collection((array) $wpdb->get_results($sql));
    }

    public function first()
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $this->table_name()) . $this->where_sql());
    }

    public function update(array $data): int
    {
        global $wpdb;
        $set = [];
        foreach ($data as $col => $value) {
            $set[] = $wpdb->prepare('%i = %s', $col, $value);
        }
        return (int) $wpdb->query($wpdb->prepare('UPDATE %i SET ', $this->table_name()) . implode(', ', $set) . $this->where_sql());
    }
}

class FF_Test_DB
{
    public function table(string $name): FF_Test_Query
    {
        return new FF_Test_Query($name);
    }

    public static function install(): void
    {
        global $wpdb;
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_forms (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL DEFAULT '',
            form_fields longtext NULL,
            PRIMARY KEY  (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_form_meta (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            form_id int(10) unsigned NULL,
            meta_key varchar(255) NOT NULL DEFAULT '',
            value longtext NULL,
            PRIMARY KEY  (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_submissions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id int(10) unsigned NULL,
            serial_number int(10) unsigned NULL,
            response longtext NULL,
            status varchar(45) NOT NULL DEFAULT 'unread',
            ip varchar(45) NULL,
            created_at timestamp NULL,
            PRIMARY KEY  (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_submission_meta (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            response_id bigint(20) unsigned NULL,
            form_id int(10) unsigned NULL,
            meta_key varchar(255) NULL,
            value longtext NULL,
            PRIMARY KEY  (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_logs (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            source_id int(10) unsigned NULL,
            source_type varchar(255) NULL,
            message longtext NULL,
            PRIMARY KEY  (id)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_entry_details (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id int(10) unsigned NULL,
            submission_id bigint(20) unsigned NULL,
            field_name varchar(255) NULL,
            field_value longtext NULL,
            PRIMARY KEY  (id)
        )");
        // Fluent Forms Pro's payment table (only the column the integration reads).
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_transactions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) unsigned NULL,
            PRIMARY KEY  (id)
        )");
    }

    public static function uninstall(): void
    {
        global $wpdb;
        foreach ([ 'fluentform_forms', 'fluentform_form_meta', 'fluentform_submissions', 'fluentform_submission_meta', 'fluentform_logs', 'fluentform_entry_details', 'fluentform_transactions' ] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
    }

    /** Test seam: insert a row into one of the Fluent Forms tables. */
    public static function seed(string $table, array $row): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . $table, $row);
        return (int) $wpdb->insert_id;
    }
}

if (! function_exists('wpFluent')) {
    function wpFluent(): FF_Test_DB
    {
        return new FF_Test_DB();
    }
}
