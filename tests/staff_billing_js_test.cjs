'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { calculate } = require('../assets/js/staff_billing.js');
const cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/staff_billing_cases.json'), 'utf8'));
let assertions = 0;
for (const item of cases) {
  const original = JSON.stringify(item.row);
  const result = calculate(item.row, item.month);
  for (const [key, expected] of Object.entries(item.expected)) {
    assert.deepStrictEqual(result[key], expected, item.label + ': ' + key);
    assertions++;
  }
  assert.strictEqual(JSON.stringify(item.row), original, item.label + ': input remains unchanged');
  assertions++;
}
console.log('Staff billing JavaScript: ' + cases.length + ' cases, ' + assertions + ' assertions passed.');
