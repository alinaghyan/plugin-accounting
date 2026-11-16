/* پنل مدیریت «افزونه‌های اختصاصی» — jQuery وردپرس + بوت‌استرپ ۵ (محدود به .wca-bs) */
(function ($) {
	'use strict';
	var $doc = $(document);

	$(function () {
		// بوت‌استرپ فقط داخل محتوای صفحه‌های افزونه فعال است.
		$('#wpbody-content > .wrap').addClass('wca-bs');

		// تولتیپ‌های بوت‌استرپ (data-bs-toggle="tooltip" یا title روی دکمه‌های آیکونی).
		if (window.bootstrap && window.bootstrap.Tooltip) {
			$('.wca-bs [data-bs-toggle="tooltip"]').each(function () { new window.bootstrap.Tooltip(this); });
		}

		// نمایش/مخفی رمز و کلیدهای API.
		$('.wca-bs input[type="password"]').each(function () {
			var $in = $(this);
			if ($in.parent().hasClass('wca-pass')) { return; }
			$in.wrap('<span class="wca-pass" style="position:relative;display:inline-flex;align-items:center;max-width:100%"></span>');
			$('<button type="button" class="button wca-pass__toggle" aria-label="نمایش" style="margin-inline-start:.4rem;min-height:38px;padding:0 .7rem"><span class="dashicons dashicons-visibility"></span></button>')
				.insertAfter($in)
				.on('click', function () {
					var show = $in.attr('type') === 'password';
					$in.attr('type', show ? 'text' : 'password');
					$(this).find('.dashicons').toggleClass('dashicons-visibility', !show).toggleClass('dashicons-hidden', show);
				});
		});

		// دکمه ذخیره هنگام ارسال فرم حالت «در حال ذخیره» می‌گیرد.
		$('.wca-bs form').on('submit', function () {
			var $b = $(this).find('[type="submit"].button-primary').first();
			if ($b.length) { setTimeout(function () { $b.addClass('is-busy'); }, 10); }
		});
	});

	function toast(msg) {
		var $t = $('.wca-toast');
		if (!$t.length) { $t = $('<div class="wca-toast" role="status" aria-live="polite"></div>').appendTo('body'); }
		$t.text(msg).addClass('is-on');
		clearTimeout($t.data('t'));
		$t.data('t', setTimeout(function () { $t.removeClass('is-on'); }, 2200));
	}
	window.wcaToast = toast;

	// تأیید قبل از عملیات حساس.
	$doc.on('click', '[data-wca-confirm]', function (e) {
		if (!window.confirm($(this).attr('data-wca-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
	});

	// کپی متن.
	$doc.on('click', '[data-wca-copy]', function (e) {
		var src = document.getElementById($(this).attr('data-wca-copy'));
		if (!src || !navigator.clipboard) { return; }
		e.preventDefault();
		navigator.clipboard.writeText(src.value || src.textContent || '').then(function () { toast('کپی شد'); });
	});
}(jQuery));
