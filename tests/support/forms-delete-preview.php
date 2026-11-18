<?php

namespace WPMCP\Tests\Support;

/**
 * Issue #946: what every forms delete-entry confirmation must say. The person
 * approving the delete sees only this text (MCP/Elicitation.php shows it), not
 * the op description, so it names the entry and its stored value count and
 * says that the delete is not an erasure.
 */
trait Forms_Delete_Preview_Assertions
{
    /**
     * @param array<string, mixed> $out      An unconfirmed delete-entry response.
     * @param string               $entry    The entry as the message names it, e.g. "Fluent Forms entry 3".
     */
    protected function assert_delete_entry_preview(array $out, string $entry, int $entry_id, int $values): void
    {
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $message = (string) $out['error']['message'];
        $this->assertStringContainsString('It permanently deletes ' . $entry, $message);
        $this->assertStringContainsString(sprintf('(%d stored values', $values), $message);
        $this->assertStringContainsString('This is not an erasure: the undo snapshot keeps a plaintext copy of the entry until the snapshot is pruned.', $message);
        $this->assertMatchesRegularExpression('/ Undo: rollback-(operation|session)\.$/', $message);

        $data = (array) $out['error']['data'];
        $this->assertSame($entry_id, $data['entry_id']);
        $this->assertSame($values, $data['values']);
        $this->assertFalse($data['erasure']);
    }
}
