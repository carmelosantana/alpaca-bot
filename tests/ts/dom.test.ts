import { test } from 'node:test';
import assert from 'node:assert/strict';
import { asId } from '../../resources/ts/dom.ts';

test('asId reads a non-negative integer id and refuses anything that would become NaN', () => {
  const cases: [unknown, number | null][] = [
    [7, 7], ['7', 7], [0, 0], ['0', 0], [' 12 ', 12],
    [undefined, null], [null, null], ['', null], ['NaN', null], [NaN, null], ['abc', null], [-1, null], [1.5, null], [Infinity, null], [{}, null], [true, null],
  ];
  for (const [input, expected] of cases) assert.equal(asId(input), expected, `asId(${JSON.stringify(input)})`);
});
