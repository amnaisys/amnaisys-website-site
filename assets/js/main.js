/**
 * AMNAISYS - Master JavaScript Utilities
 * Lightweight Vanilla JS for Navigation, Accordions & Accessibility
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Mobile Navigation Toggle
  const navToggle = document.querySelector('.nav-toggle');
  const navMenu = document.querySelector('.nav-menu');

  if (navToggle && navMenu) {
    navToggle.addEventListener('click', () => {
      const isExpanded = navToggle.getAttribute('aria-expanded') === 'true';
      navToggle.setAttribute('aria-expanded', !isExpanded);
      navMenu.classList.toggle('is-active');
      document.body.classList.toggle('nav-open');
    });

    // Close menu when clicking any nav link
    const navLinks = navMenu.querySelectorAll('a');
    navLinks.forEach((link) => {
      link.addEventListener('click', () => {
        navToggle.setAttribute('aria-expanded', 'false');
        navMenu.classList.remove('is-active');
        document.body.classList.remove('nav-open');
      });
    });

    // Close menu when pressing Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && navMenu.classList.contains('is-active')) {
        navToggle.setAttribute('aria-expanded', 'false');
        navMenu.classList.remove('is-active');
        document.body.classList.remove('nav-open');
        navToggle.focus();
      }
    });
  }

  // 2. Accessible FAQ Accordions
  const faqItems = document.querySelectorAll('.faq-item');
  faqItems.forEach((item) => {
    const questionBtn = item.querySelector('.faq-question');
    if (questionBtn) {
      questionBtn.addEventListener('click', () => {
        const isOpen = item.classList.contains('is-open');
        
        // Dusre sabhi open accordions ko band karein
        faqItems.forEach((other) => other.classList.remove('is-open'));
        
        if (!isOpen) {
          item.classList.add('is-open');
        }
      });
    }
  });

  // 3. Auto Dynamic Year in Footer
  const yearElements = document.querySelectorAll('.current-year');
  const currentYear = new Date().getFullYear();
  yearElements.forEach((el) => {
    el.textContent = currentYear;
  });
});