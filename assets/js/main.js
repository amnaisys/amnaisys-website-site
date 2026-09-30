/**
 * AMNAISYS - Master JavaScript Utilities
 * Lightweight Vanilla JS for navigation, service discovery, forms,
 * reveal motion, FAQ support & accessibility.
 */

document.addEventListener('DOMContentLoaded', () => {
  // 0. Google Material Symbols with a safe local fallback while the webfont loads.
  if (document.fonts && document.querySelector('.material-symbols-rounded')) {
    document.fonts.load('400 24px "Material Symbols Rounded"').then((faces) => {
      if (faces && faces.length) document.documentElement.classList.add('material-symbols-ready');
    }).catch(() => {
      // The CSS fallback remains visible if the remote icon font is unavailable.
    });
  }

  // 1. Lightweight reveal motion (progressive enhancement)
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!prefersReducedMotion) {
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

  // 2. Mobile Navigation Toggle
  const navToggle = document.querySelector('.nav-toggle');
  const navMenu = document.querySelector('.nav-menu');
  const pageIsArabic = document.documentElement.lang.toLowerCase().startsWith('ar');
  const setNavToggleLabel = (open) => {
    if (!navToggle) return;
    navToggle.setAttribute('aria-label', open
      ? (pageIsArabic ? 'إغلاق قائمة التنقل' : 'Close navigation menu')
      : (pageIsArabic ? 'فتح قائمة التنقل' : 'Open navigation menu'));
  };

  if (navToggle && navMenu) {
    setNavToggleLabel(false);
    const closeNavigation = (returnFocus = false) => {
      navToggle.setAttribute('aria-expanded', 'false');
      setNavToggleLabel(false);
      navMenu.classList.remove('is-active');
      document.body.classList.remove('nav-open');
      const mobileServices = navMenu.querySelector('.nav-services');
      const mobileServicesToggle = navMenu.querySelector('.nav-services-mobile-toggle');
      if (mobileServices) mobileServices.classList.remove('is-mobile-open');
      if (mobileServicesToggle) mobileServicesToggle.setAttribute('aria-expanded', 'false');
      if (returnFocus) navToggle.focus();
    };

    navToggle.addEventListener('click', () => {
      const isExpanded = navToggle.getAttribute('aria-expanded') === 'true';
      if (isExpanded) {
        closeNavigation();
      } else {
        navToggle.setAttribute('aria-expanded', 'true');
        setNavToggleLabel(true);
        navMenu.classList.add('is-active');
        document.body.classList.add('nav-open');
        const firstLink = navMenu.querySelector('a[href]');
        if (firstLink) firstLink.focus({ preventScroll: true });
      }
    });

    navMenu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => closeNavigation());
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && navMenu.classList.contains('is-active')) {
        closeNavigation(true);
      }
    });

    window.matchMedia('(min-width: 1081px)').addEventListener('change', (event) => {
      if (event.matches) closeNavigation();
    });
  }

  // 3. Desktop Services mega-menu + More dropdown
  const desktopNav = window.matchMedia('(min-width: 1081px)');
  const servicesNav = document.querySelector('.nav-services');
  const servicesLink = servicesNav?.querySelector('.nav-services-link');
  const servicesMobileToggle = servicesNav?.querySelector('.nav-services-mobile-toggle');
  const setServicesToggleLabel = (open) => {
    if (!servicesMobileToggle) return;
    servicesMobileToggle.setAttribute('aria-label', open
      ? (pageIsArabic ? 'إخفاء روابط الخدمات' : 'Hide service links')
      : (pageIsArabic ? 'إظهار روابط الخدمات' : 'Show service links'));
  };
  const serviceMenuLinks = servicesNav ? [...servicesNav.querySelectorAll('.nav-service-link')] : [];
  const moreNav = document.querySelector('.nav-more');
  const moreToggle = moreNav?.querySelector('.nav-more-toggle');
  const moreLinks = moreNav ? [...moreNav.querySelectorAll('.nav-more-menu a')] : [];
  let servicesCloseTimer = null;
  let moreCloseTimer = null;

  const closeServices = (returnFocus = false) => {
    if (!servicesNav || !servicesLink) return;
    window.clearTimeout(servicesCloseTimer);
    servicesNav.classList.remove('is-open');
    servicesLink.setAttribute('aria-expanded', 'false');
    if (returnFocus) servicesLink.focus();
  };

  const openServices = (focusFirst = false) => {
    if (!servicesNav || !servicesLink || !desktopNav.matches) return;
    window.clearTimeout(servicesCloseTimer);
    closeMore(false);
    servicesNav.classList.add('is-open');
    servicesLink.setAttribute('aria-expanded', 'true');
    if (focusFirst && serviceMenuLinks.length) serviceMenuLinks[0].focus();
  };

  const scheduleCloseServices = () => {
    window.clearTimeout(servicesCloseTimer);
    servicesCloseTimer = window.setTimeout(() => closeServices(false), 130);
  };

  const closeMore = (returnFocus = false) => {
    if (!moreNav || !moreToggle) return;
    window.clearTimeout(moreCloseTimer);
    moreNav.classList.remove('is-open');
    moreToggle.setAttribute('aria-expanded', 'false');
    if (returnFocus) moreToggle.focus();
  };

  const openMore = (focusFirst = false) => {
    if (!moreNav || !moreToggle || !desktopNav.matches) return;
    window.clearTimeout(moreCloseTimer);
    closeServices(false);
    moreNav.classList.add('is-open');
    moreToggle.setAttribute('aria-expanded', 'true');
    if (focusFirst && moreLinks.length) moreLinks[0].focus();
  };

  const scheduleCloseMore = () => {
    window.clearTimeout(moreCloseTimer);
    moreCloseTimer = window.setTimeout(() => closeMore(false), 130);
  };

  if (servicesNav && servicesLink) {
    servicesNav.addEventListener('pointerenter', () => openServices(false));
    servicesNav.addEventListener('pointerleave', scheduleCloseServices);
    servicesNav.addEventListener('focusin', () => openServices(false));
    servicesNav.addEventListener('focusout', (event) => {
      if (!servicesNav.contains(event.relatedTarget)) scheduleCloseServices();
    });

    servicesLink.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' && desktopNav.matches) {
        event.preventDefault();
        openServices(true);
      } else if (event.key === 'Escape') {
        closeServices(true);
      }
    });

    serviceMenuLinks.forEach((link, index) => {
      link.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          serviceMenuLinks[(index + 1) % serviceMenuLinks.length].focus();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          serviceMenuLinks[(index - 1 + serviceMenuLinks.length) % serviceMenuLinks.length].focus();
        } else if (event.key === 'Escape') {
          event.preventDefault();
          closeServices(true);
        }
      });
    });
  }

  if (moreNav && moreToggle) {
    moreNav.addEventListener('pointerenter', () => openMore(false));
    moreNav.addEventListener('pointerleave', scheduleCloseMore);
    moreNav.addEventListener('focusin', () => openMore(false));
    moreNav.addEventListener('focusout', (event) => {
      if (!moreNav.contains(event.relatedTarget)) scheduleCloseMore();
    });

    moreToggle.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (moreNav.classList.contains('is-open')) closeMore();
      else openMore(false);
    });

    moreToggle.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        openMore(true);
      } else if (event.key === 'Escape') {
        closeMore(true);
      }
    });

    moreLinks.forEach((link, index) => {
      link.addEventListener('click', () => closeMore());
      link.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          moreLinks[(index + 1) % moreLinks.length].focus();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          moreLinks[(index - 1 + moreLinks.length) % moreLinks.length].focus();
        } else if (event.key === 'Escape') {
          event.preventDefault();
          closeMore(true);
        }
      });
    });
  }

  document.querySelectorAll('.nav-item:not(.nav-more):not(.nav-services)').forEach((item) => {
    item.addEventListener('pointerenter', () => {
      closeMore(false);
      closeServices(false);
    });
  });

  document.addEventListener('click', (event) => {
    if (moreNav && !moreNav.contains(event.target)) closeMore(false);
  });

  window.addEventListener('scroll', () => {
    closeMore(false);
    closeServices(false);
  }, { passive: true });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      if (moreNav?.classList.contains('is-open')) closeMore(true);
      if (servicesNav?.classList.contains('is-open')) closeServices(true);
    }
  });

  const closeMobileServices = () => {
    if (!servicesNav || !servicesMobileToggle) return;
    servicesNav.classList.remove('is-mobile-open');
    servicesMobileToggle.setAttribute('aria-expanded', 'false');
    setServicesToggleLabel(false);
  };

  if (servicesMobileToggle && servicesNav) {
    setServicesToggleLabel(false);
    servicesMobileToggle.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (desktopNav.matches) return;
      const willOpen = !servicesNav.classList.contains('is-mobile-open');
      servicesNav.classList.toggle('is-mobile-open', willOpen);
      servicesMobileToggle.setAttribute('aria-expanded', String(willOpen));
      setServicesToggleLabel(willOpen);
    });
  }

  const syncDesktopNavigation = () => {
    closeMore(false);
    closeServices(false);
    closeMobileServices();
    if (servicesLink) servicesLink.setAttribute('aria-expanded', 'false');
  };
  desktopNav.addEventListener('change', syncDesktopNavigation);
  syncDesktopNavigation();

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

  // 5. Accessible FAQ Accordions (legacy production markup)
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

  // 6. Dynamic year in footer
  const currentYear = new Date().getFullYear();
  document.querySelectorAll('.current-year').forEach((el) => {
    el.textContent = currentYear;
  });
});
