(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.G4FStaffBilling = api;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';
  var dayMilliseconds = 86400000;

  function dateValue(value) {
    if (typeof value !== 'string') return null;
    var match = /^(19\d{2}|20\d{2}|2100)-(\d{2})-(\d{2})$/.exec(value);
    if (!match) return null;
    var year = Number(match[1]), month = Number(match[2]), day = Number(match[3]);
    var timestamp = Date.UTC(year, month - 1, day), date = new Date(timestamp);
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? timestamp : null;
  }

  // Match the server's saved-register validation without changing the entered value.
  function moneyCents(value) {
    if (typeof value !== 'string' && !Number.isInteger(value)) return null;
    var raw = String(value).trim().replace(/^R\$\s*/, '');
    if (raw.indexOf(',') >= 0) {
      if (!/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/.test(raw)) return null;
      raw = raw.replace(/\./g, '').replace(',', '.');
    }
    if (!/^\d{1,8}(?:\.\d{1,2})?$/.test(raw)) return null;
    var parts = raw.split('.');
    return Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
  }

  function decimal(cents) {
    return String(Math.floor(cents / 100)) + '.' + String(cents % 100).padStart(2, '0');
  }

  function calculate(row, month) {
    row = row || {};
    var departureProvided = row.departure_date !== null && row.departure_date !== undefined && row.departure_date !== '';
    var result = { billing_first_day: null, billing_last_day: null, automatic_billed_days: null, billed_days_override: null, billed_days: null, billed_value: null, billing_month_days: null, status: departureProvided ? 'Inativo' : 'Ativo' };
    var period = typeof month === 'string' && /^(20\d{2}|2100)-(0[1-9]|1[0-2])$/.exec(month);
    if (!period) return result;
    var year = Number(period[1]), monthNumber = Number(period[2]);
    var first = Date.UTC(year, monthNumber - 1, 1), last = Date.UTC(year, monthNumber, 0);
    result.billing_month_days = Math.round((last - first) / dayMilliseconds) + 1;
    var excluded = Object.prototype.hasOwnProperty.call(row, 'not_billed') ? row.not_billed : row.billed;
    excluded = excluded === true || excluded === 1 || excluded === '1';
    if (excluded) { result.billed_days = 0; result.billed_value = '0.00'; }
    var admission = dateValue(row.admission_date), departure = departureProvided ? dateValue(row.departure_date) : last;
    if (admission === null || departure === null || (departureProvided && departure < admission)) return result;
    first = Math.max(first, admission); last = Math.min(last, departure);
    var automaticDays = first > last ? 0 : Math.round((last - first) / dayMilliseconds) + 1;
    result.automatic_billed_days = automaticDays;
    var override = row.billing_days_overrides && typeof row.billing_days_overrides === 'object' ? row.billing_days_overrides[month] : null;
    if (override !== undefined && override !== null && override !== '') {
      var validInteger = Number.isInteger(override) || (typeof override === 'string' && /^(?:0|[1-9]\d*)$/.test(override));
      if (!validInteger || Number(override) < 0 || Number(override) > automaticDays) return result;
      result.billed_days_override = Number(override);
    }
    if (excluded) return result;
    if (first > last) {
      result.billed_days = 0; result.billed_value = '0.00'; return result;
    }
    result.billing_first_day = new Date(first).toISOString().slice(0, 10);
    result.billing_last_day = new Date(last).toISOString().slice(0, 10);
    result.billed_days = result.billed_days_override === null ? automaticDays : result.billed_days_override;
    var cents = moneyCents(row.monthly_value);
    if (cents !== null) {
      // Round only the final amount, using integer cents and half-up rounding.
      var billedCents = Math.floor((cents * result.billed_days * 2 + result.billing_month_days) / (2 * result.billing_month_days));
      result.billed_value = decimal(billedCents);
    }
    return result;
  }

  return { calculate: calculate };
});
