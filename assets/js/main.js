/**
 * AMNAISYS - Master JavaScript Utilities
 * Lightweight Vanilla JS for navigation, disclosure menus, forms,
 * reveal motion, magnetic conversion controls, FAQ support & accessibility.
 */

document.addEventListener('DOMContentLoaded', () => {
  // 0. Google Material Symbols with a safe local fallback while the webfont loads.
  if (document.fonts && document.querySelector('.material-symbols-rounded')) {
    document.fonts.load('400 24px "Material Symbols Rounded"').then((faces) => {
      if (faces && faces.length) document.documentElement.classList.add('material-symbols-ready');
    }).catch(() => {});
  }

  // 1. Lightweight reveal motion (progressive enhancement).
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (!reducedMotionQuery.matches) {
    const revealNodes = [...document.querySelectorAll('[data-reveal]')];
    if (revealNodes.length) {
      document.documentElement.classList.add('motion-ready');
      revealNodes.forEach((node) => node.classList.add('reveal-pending'));
      if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              entry.target.classList.add('is-revealed');
              observer.unobserve(entry.target);
            }
          });
        }, { threshold: 0.12 });
        revealNodes.forEach((node) => observer.observe(node));
      } else {
        revealNodes.forEach((node) => node.classList.add('is-revealed'));
      }
    }
  }

  const pageIsArabic = document.documentElement.lang.toLowerCase().startsWith('ar');
  const desktopNav = window.matchMedia('(min-width: 1081px)');
  const navToggle = document.querySelector('.nav-toggle');
  const navMenu = document.querySelector('.nav-menu');
  const disclosures = [...document.querySelectorAll('.nav-services, .nav-industries')].map((root) => {
    const isIndustries = root.classList.contains('nav-industries');
    return {
      root,
      link: root.querySelector(isIndustries ? '.nav-industries-link' : '.nav-services-link'),
      mobileToggle: root.querySelector(isIndustries ? '.nav-industries-mobile-toggle' : '.nav-services-mobile-toggle'),
      links: [...root.querySelectorAll(isIndustries ? '.nav-industry-link' : '.nav-service-link')],
      nounEn: isIndustries ? 'industry' : 'service',
      nounAr: isIndustries ? 'القطاعات' : 'الخدمات',
      timer: null
    };
  });

  const setNavToggleLabel = (open) => {
    if (!navToggle) return;
    navToggle.setAttribute('aria-label', open
      ? (pageIsArabic ? 'إغلاق قائمة التنقل' : 'Close navigation menu')
      : (pageIsArabic ? 'فتح قائمة التنقل' : 'Open navigation menu'));
  };

  const setDisclosureLabel = (d, open) => {
    if (!d.mobileToggle) return;
    const label = pageIsArabic
      ? `${open ? 'إخفاء' : 'إظهار'} روابط ${d.nounAr}`
      : `${open ? 'Hide' : 'Show'} ${d.nounEn} links`;
    d.mobileToggle.setAttribute('aria-label', label);
  };

  const closeDisclosure = (d, returnFocus = false) => {
    if (!d?.root || !d.link) return;
    window.clearTimeout(d.timer);
    d.root.classList.remove('is-open');
    d.link.setAttribute('aria-expanded', 'false');
    if (returnFocus) d.link.focus();
  };

  const closeMobileDisclosure = (d) => {
    if (!d?.root || !d.mobileToggle) return;
    d.root.classList.remove('is-mobile-open');
    d.mobileToggle.setAttribute('aria-expanded', 'false');
    setDisclosureLabel(d, false);
  };

  const closeAllDisclosures = (except = null) => {
    disclosures.forEach((d) => {
      if (d !== except) {
        closeDisclosure(d, false);
        closeMobileDisclosure(d);
      }
    });
  };

  const openDisclosure = (d, focusFirst = false) => {
    if (!d?.root || !d.link || !desktopNav.matches) return;
    window.clearTimeout(d.timer);
    closeAllDisclosures(d);
    d.root.classList.add('is-open');
    d.link.setAttribute('aria-expanded', 'true');
    if (focusFirst && d.links.length) d.links[0].focus();
  };

  disclosures.forEach((d) => {
    if (!d.root || !d.link) return;
    d.link.setAttribute('aria-expanded', 'false');
    if (d.mobileToggle) setDisclosureLabel(d, false);

    d.root.addEventListener('pointerenter', () => openDisclosure(d, false));
    d.root.addEventListener('pointerleave', () => {
      window.clearTimeout(d.timer);
      d.timer = window.setTimeout(() => closeDisclosure(d, false), 130);
    });
    d.root.addEventListener('focusin', () => openDisclosure(d, false));
    d.root.addEventListener('focusout', (event) => {
      if (!d.root.contains(event.relatedTarget)) {
        window.clearTimeout(d.timer);
        d.timer = window.setTimeout(() => closeDisclosure(d, false), 130);
      }
    });

    d.link.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' && desktopNav.matches) {
        event.preventDefault();
        openDisclosure(d, true);
      } else if (event.key === 'Escape') {
        closeDisclosure(d, true);
      }
    });

    d.links.forEach((link, index) => {
      link.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          d.links[(index + 1) % d.links.length].focus();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          d.links[(index - 1 + d.links.length) % d.links.length].focus();
        } else if (event.key === 'Escape') {
          event.preventDefault();
          closeDisclosure(d, true);
        }
      });
    });

    if (d.mobileToggle) {
      d.mobileToggle.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (desktopNav.matches) return;
        const willOpen = !d.root.classList.contains('is-mobile-open');
        closeAllDisclosures(d);
        d.root.classList.toggle('is-mobile-open', willOpen);
        d.mobileToggle.setAttribute('aria-expanded', String(willOpen));
        setDisclosureLabel(d, willOpen);
      });
    }
  });

  // 2. Mobile navigation toggle with deterministic focus lifecycle.
  if (navToggle && navMenu) {
    setNavToggleLabel(false);
    const inertTargets = [...document.querySelectorAll('main, footer, .floating-contact-actions, .mobile-sticky-bar')];
    const setBackgroundInert = (state) => inertTargets.forEach((el) => {
      if ('inert' in el) el.inert = state;
      if (state) el.setAttribute('aria-hidden', 'true');
      else el.removeAttribute('aria-hidden');
    });
    const compactFocusables = () => [navToggle, ...navMenu.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')]
      .filter((el, index, list) => el && list.indexOf(el) === index && el.offsetParent !== null);

    const closeNavigation = (returnFocus = false) => {
      navToggle.setAttribute('aria-expanded', 'false');
      setNavToggleLabel(false);
      navMenu.classList.remove('is-active');
      document.body.classList.remove('nav-open');
      setBackgroundInert(false);
      disclosures.forEach((d) => closeMobileDisclosure(d));
      if (returnFocus) requestAnimationFrame(() => navToggle.focus({ preventScroll: true }));
    };

    const openNavigation = () => {
      navToggle.setAttribute('aria-expanded', 'true');
      setNavToggleLabel(true);
      navMenu.classList.add('is-active');
      document.body.classList.add('nav-open');
      setBackgroundInert(true);
      // Wait until the drawer is painted as visible before transferring focus.
      requestAnimationFrame(() => requestAnimationFrame(() => {
        const firstLink = navMenu.querySelector('a[href]');
        if (firstLink) firstLink.focus({ preventScroll: true });
      }));
    };

    navToggle.addEventListener('click', () => {
      const isExpanded = navToggle.getAttribute('aria-expanded') === 'true';
      if (isExpanded) closeNavigation(false);
      else openNavigation();
    });

    navMenu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => closeNavigation(false)));
    document.addEventListener('keydown', (event) => {
      if (!navMenu.classList.contains('is-active')) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeNavigation(true);
        return;
      }
      if (event.key === 'Tab') {
        const focusables = compactFocusables();
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    window.matchMedia('(min-width: 1081px)').addEventListener('change', (event) => {
      if (event.matches) closeNavigation(false);
    });
  }

  // Close dropdowns when moving to ordinary navigation items, scrolling or pressing Escape.
  document.querySelectorAll('.nav-item:not(.nav-services):not(.nav-industries)').forEach((item) => {
    item.addEventListener('pointerenter', () => closeAllDisclosures());
  });
  window.addEventListener('scroll', () => disclosures.forEach((d) => closeDisclosure(d, false)), { passive: true });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') disclosures.forEach((d) => {
      if (d.root.classList.contains('is-open')) closeDisclosure(d, true);
    });
  });
  desktopNav.addEventListener('change', () => {
    disclosures.forEach((d) => {
      closeDisclosure(d, false);
      closeMobileDisclosure(d);
    });
  });

  // 3. Restrained magnetic conversion controls: fine pointer only, no dependencies.
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  if (finePointer.matches && !reducedMotionQuery.matches) {
    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    document.querySelectorAll('.magnetic-cta').forEach((target) => {
      let frame = null;
      const reset = () => {
        if (frame) cancelAnimationFrame(frame);
        target.style.setProperty('--mag-x', '0px');
        target.style.setProperty('--mag-y', '0px');
      };
      target.addEventListener('pointermove', (event) => {
        if (frame) cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
          const rect = target.getBoundingClientRect();
          const dx = event.clientX - (rect.left + rect.width / 2);
          const dy = event.clientY - (rect.top + rect.height / 2);
          const x = clamp(dx * 0.09, -5, 5);
          const y = clamp(dy * 0.09, -5, 5);
          target.style.setProperty('--mag-x', `${x.toFixed(2)}px`);
          target.style.setProperty('--mag-y', `${y.toFixed(2)}px`);
        });
      }, { passive: true });
      target.addEventListener('pointerleave', reset, { passive: true });
      target.addEventListener('blur', reset);
    });
  }

  // 4. Consultation deep-link: ?service=<service-slug> selects the matching service.
  const consultationSelect = document.querySelector('select[name="serviceInterest"]');
  if (consultationSelect) {
    const requestedService = new URLSearchParams(window.location.search).get('service');
    if (requestedService) {
      const matchingOption = [...consultationSelect.options].find((option) => option.value === requestedService);
      if (matchingOption) {
        consultationSelect.value = requestedService;
        consultationSelect.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }

  // 5. Accessible FAQ accordions (legacy production markup only).
  const faqItems = document.querySelectorAll('.faq-item');
  faqItems.forEach((item) => {
    const questionBtn = item.querySelector('.faq-question');
    if (questionBtn) {
      questionBtn.addEventListener('click', () => {
        const isOpen = item.classList.contains('is-open');
        faqItems.forEach((other) => other.classList.remove('is-open'));
        if (!isOpen) item.classList.add('is-open');
      });
    }
  });

  // 6. Respond to motion-preference changes without requiring a page reload.
  const applyReducedMotionState = () => {
    if (reducedMotionQuery.matches) {
      document.documentElement.classList.remove('motion-ready');
      document.querySelectorAll('[data-reveal]').forEach((node) => {
        node.classList.remove('reveal-pending');
        node.classList.add('is-revealed');
      });
      document.querySelectorAll('.magnetic-cta').forEach((node) => {
        node.style.setProperty('--mag-x', '0px');
        node.style.setProperty('--mag-y', '0px');
      });
    }
  };
  reducedMotionQuery.addEventListener?.('change', applyReducedMotionState);

  // 7. Form-control hardening: telephone character contract + select chevrons.
  const sanitizePhoneValue = (value) => {
    let output = '';
    for (const char of value) {
      const isDigit = /[0-9٠-٩]/.test(char);
      if (isDigit) {
        output += char;
        continue;
      }
      if (char === '+' && output.length === 0) {
        output = '+';
        continue;
      }
      if (/\s/.test(char) && output && output !== '+' && !output.endsWith(' ')) {
        output += ' ';
      }
    }
    return output;
  };

  document.querySelectorAll('input[data-phone-input="true"]').forEach((input) => {
    input.addEventListener('input', () => {
      const original = input.value;
      const start = input.selectionStart ?? original.length;
      const beforeCaret = sanitizePhoneValue(original.slice(0, start));
      const sanitized = sanitizePhoneValue(original);
      if (sanitized !== original) {
        input.value = sanitized;
        const nextCaret = Math.min(beforeCaret.length, sanitized.length);
        try { input.setSelectionRange(nextCaret, nextCaret); } catch (_) { /* selection APIs vary for tel controls */ }
      }
    });
  });

  const supportsNativeSelectOpen = typeof CSS !== 'undefined' &&
    typeof CSS.supports === 'function' && CSS.supports('selector(select:open)');

  document.querySelectorAll('.select-shell > select').forEach((select) => {
    const shell = select.closest('.select-shell');
    if (!shell || supportsNativeSelectOpen) return;

    const open = () => shell.classList.add('is-open');
    const close = () => shell.classList.remove('is-open');
    const toggle = () => shell.classList.toggle('is-open');

    select.addEventListener('pointerdown', toggle);
    select.addEventListener('change', close);
    select.addEventListener('input', close);
    select.addEventListener('blur', close);
    select.addEventListener('keydown', (event) => {
      if (['Enter', ' '].includes(event.key)) toggle();
      if (['ArrowDown', 'ArrowUp'].includes(event.key)) open();
      if (['Escape', 'Tab'].includes(event.key)) close();
    });
  });

  // 8. Prevent accidental duplicate form submissions while preserving native validation.
  document.querySelectorAll('form[action*="submit-form.php"]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!form.checkValidity()) return;
      if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
      form.dataset.submitting = 'true';
      form.classList.add('is-submitting');
      const button = form.querySelector('button[type="submit"]');
      const status = form.querySelector('.form-status-live');
      if (button) {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
        button.textContent = button.dataset.submitSending || (pageIsArabic ? 'جارٍ الإرسال…' : 'Sending…');
      }
      if (status) status.textContent = pageIsArabic ? 'جارٍ إرسال الطلب بأمان…' : 'Sending your request securely…';
    });
  });

  // 9. Dynamic year in footer.
  const currentYear = String(new Date().getFullYear());
  const localizedYear = pageIsArabic ? currentYear.replace(/[0-9]/g, (d) => '٠١٢٣٤٥٦٧٨٩'[Number(d)]) : currentYear;
  document.querySelectorAll('.current-year').forEach((el) => { el.textContent = localizedYear; });
});
