import { test } from 'node:test';
import assert from 'node:assert/strict';
import { highlight } from '../../resources/ts/highlight.ts';

test('highlights php keywords, strings, comments and escapes html', () => {
  const out = highlight('<?php // hi\n$x = "a<b"; return $x;', 'php');
  assert.match(out, /<span class="tok-cmt">\/\/ hi<\/span>/);
  assert.match(out, /<span class="tok-str">&quot;a&lt;b&quot;<\/span>/);
  assert.match(out, /<span class="tok-kw">return<\/span>/);
  assert.ok(!out.includes('<b'));
});

test('unknown language is escaped only', () => {
  assert.equal(highlight('a < b', 'brainfuck'), 'a &lt; b');
});
