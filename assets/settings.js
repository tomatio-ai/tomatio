/**
 * #751: the Save bar on settings forms (Admin\Ui::saveBar). It stays hidden
 * until a field differs from what the page loaded with, says how many
 * changed, and asks before leaving with unsaved changes. Discard is the
 * form's reset button. Without this script the bar is simply always shown.
 */
(function () {
	'use strict';

	function changed(form) {
		var names = {};
		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'reset' || el.type === 'button' || el.disabled) {
				return;
			}
			var differs;
			if (el.type === 'checkbox' || el.type === 'radio') {
				differs = el.checked !== el.defaultChecked;
			} else if (el.tagName === 'SELECT') {
				differs = Array.prototype.some.call(el.options, function (o) { return o.selected !== o.defaultSelected; });
			} else {
				differs = el.value !== el.defaultValue;
			}
			if (differs) {
				names[el.name] = true;
			}
		});

		return Object.keys(names).length;
	}

	var dirty = 0;

	Array.prototype.forEach.call(document.querySelectorAll('.ab-ui-savebar'), function (bar) {
		var form = bar.closest('form');
		if (!form) {
			return;
		}
		var msg = bar.querySelector('.ab-ui-savebar-msg');
		var count = 0;

		function update() {
			var now = changed(form);
			dirty += now - count;
			count = now;
			bar.hidden = now === 0;
			if (msg) {
				msg.textContent = now === 1 ? bar.getAttribute('data-one') : (bar.getAttribute('data-many') || '').replace('%d', String(now));
			}
		}

		form.addEventListener('input', update);
		form.addEventListener('change', update);
		form.addEventListener('reset', function () { window.setTimeout(update, 0); });
		form.addEventListener('submit', function () { dirty -= count; count = 0; });
		update();
	});

	window.addEventListener('beforeunload', function (event) {
		if (dirty > 0) {
			event.preventDefault();
			event.returnValue = '';
		}
	});
}());
