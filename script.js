/* Olive Sprigs Health Solutions — site behaviour
   01 Navigation & header   03 Service jump bar
   02 Reveal on scroll      04 Website forms           */
document.documentElement.classList.add('js');
document.body.classList.add('js');
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* 01. Accessible mobile navigation & sticky header */
const header = document.querySelector('.header');
const menu = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#site-nav');
const desktopNav = window.matchMedia('(min-width: 1024px)');

if (menu && navigation) {
  menu.hidden = false;
  const label = menu.querySelector('.menu-label');
  const setOpen = (open) => {
    if (open) navigation.style.setProperty('--nav-top', `${Math.max(0, header.getBoundingClientRect().bottom)}px`);
    menu.setAttribute('aria-expanded', String(open));
    menu.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    label.textContent = open ? 'Close' : 'Menu';
    navigation.classList.toggle('is-open', open);
    document.body.classList.toggle('nav-locked', open);
  };
  const isOpen = () => menu.getAttribute('aria-expanded') === 'true';
  menu.addEventListener('click', () => setOpen(!isOpen()));
  navigation.addEventListener('click', (event) => { if (event.target.closest('a')) setOpen(false); });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) { setOpen(false); menu.focus(); }
  });
  // Keep keyboard focus inside the open mobile menu
  navigation.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab' || !isOpen()) return;
    const items = [menu, ...navigation.querySelectorAll('a')];
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === navigation.querySelector('a')) { event.preventDefault(); first.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  desktopNav.addEventListener('change', (event) => { if (event.matches) setOpen(false); });
}

if (header) {
  const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 40);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
}

const year = document.querySelector('#year');
if (year) year.textContent = new Date().getFullYear();

/* 02. Gentle reveal as sections enter the viewport */
const revealItems = document.querySelectorAll('[data-reveal]');
if ('IntersectionObserver' in window && !reduceMotion) {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
  revealItems.forEach((item) => observer.observe(item));
} else {
  revealItems.forEach((item) => item.classList.add('is-visible'));
}

/* 03. Highlight the chosen service on the jump bar */
const jumpLinks = [...document.querySelectorAll('.jump-nav a')];
if (jumpLinks.length) {
  const mark = () => jumpLinks.forEach((link) => {
    if (link.hash === window.location.hash) {
      link.setAttribute('aria-current', 'true');
      link.scrollIntoView({ block: 'nearest', inline: 'center' });
    } else link.removeAttribute('aria-current');
  });
  window.addEventListener('hashchange', mark);
  mark();
}

/* 04. Website forms (care requests and job applications)
   Submit to the server and only show success once the server has saved it.
   Without JavaScript the same forms post normally and the server shows a result page. */
const RULES = {
  first_name: (el) => (el.validity.valueMissing ? 'Please enter your first name.' : ''),
  last_name: (el) => (el.validity.valueMissing ? 'Please enter your last name.' : ''),
  email: (el) => {
    if (el.validity.valueMissing) return 'Please add your email so we can reply.';
    if (el.validity.typeMismatch || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim())) return 'That email doesn’t look quite right. Please check it, for example name@example.com.';
    return '';
  },
  phone: (el) => {
    if (el.validity.valueMissing) return 'Please add a phone number so we can call you back.';
    if (el.value.replace(/\D/g, '').length < 10) return 'Please enter a full phone number, including the area code.';
    return '';
  },
  position: (el) => (el.validity.valueMissing ? 'Please choose the position you are interested in.' : ''),
  resume: (el) => {
    const file = el.files && el.files[0];
    if (!file) return '';
    if (!/\.(pdf|docx?)$/i.test(file.name)) return 'Please attach a PDF or Word document (.pdf, .doc, .docx).';
    if (file.size > Number(el.dataset.maxBytes || 5242880)) return 'This file is larger than 5 MB. Please attach a smaller file.';
    if (file.size === 0) return 'This file appears to be empty. Please choose another.';
    return '';
  }
};

document.querySelectorAll('form[data-ajax-form]').forEach((form) => {
  form.noValidate = true; // JavaScript provides friendlier inline messages
  const button = form.querySelector('[type="submit"]');
  const buttonLabel = button.querySelector('.button-label');
  const buttonIcon = button.querySelector('svg');
  const defaultLabel = buttonLabel.textContent;
  const errorBox = form.querySelector('.alert-error');
  const status = form.querySelector('.form-status');
  const success = document.querySelector(form.dataset.success);

  // Pre-select the service when arriving from a link such as contact.html?service=pediatric
  if (form.hasAttribute('data-preset-service')) {
    const wanted = new URLSearchParams(window.location.search).get('service');
    const preset = wanted && form.querySelector(`select[name="service"] option[data-key="${CSS.escape(wanted)}"]`);
    if (preset) preset.selected = true;
  }

  // Character counters
  const counters = [...form.querySelectorAll('textarea[data-counter]')].map((area) => {
    const out = form.querySelector(area.dataset.counter);
    const update = () => { out.textContent = `${area.value.length} / ${area.maxLength}`; };
    area.addEventListener('input', update);
    update();
    return update;
  });

  const watched = [...form.elements].filter((el) => RULES[el.name] && el.closest('.field')?.querySelector('.field-error'));
  const validateField = (el) => {
    const problem = RULES[el.name](el);
    const field = el.closest('.field');
    field.classList.toggle('has-error', Boolean(problem));
    field.querySelector('.field-error span').textContent = problem;
    el.setAttribute('aria-invalid', String(Boolean(problem)));
    return !problem;
  };
  watched.forEach((el) => {
    el.addEventListener('blur', () => { if (el.value || el.closest('.has-error')) validateField(el); });
    el.addEventListener('input', () => { if (el.closest('.has-error')) validateField(el); });
    el.addEventListener('change', () => { if (el.type === 'file' || el.closest('.has-error')) validateField(el); });
  });

  const setBusy = (busy) => {
    button.disabled = busy;
    button.setAttribute('aria-busy', String(busy));
    buttonLabel.textContent = busy ? (form.dataset.busy || 'Sending…') : defaultLabel;
    if (busy) { const spin = document.createElement('span'); spin.className = 'spinner'; spin.setAttribute('aria-hidden', 'true'); buttonIcon.replaceWith(spin); }
    else { button.querySelector('.spinner')?.replaceWith(buttonIcon); }
  };
  const showError = (text) => {
    errorBox.querySelector('.alert-message').textContent = text;
    errorBox.hidden = false;
  };
  const flagField = (name, text) => {
    const el = name && form.elements[name];
    if (!el || !el.closest || !el.closest('.field')?.querySelector('.field-error')) return false;
    const field = el.closest('.field');
    field.classList.add('has-error');
    field.querySelector('.field-error span').textContent = text;
    el.setAttribute('aria-invalid', 'true');
    el.focus();
    return true;
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorBox.hidden = true;
    const invalid = watched.filter((el) => !validateField(el));
    if (invalid.length) {
      invalid[0].focus();
      status.textContent = `Please check ${invalid.length === 1 ? 'one field' : `${invalid.length} fields`} before sending.`;
      return;
    }
    setBusy(true);
    status.textContent = 'Sending.';
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      });
      let result;
      try { result = await response.json(); } catch { throw new Error('Our online form isn’t available right now.'); }
      if (!response.ok || !result.ok) {
        const error = new Error(result.message || 'Something went wrong on our side.');
        error.field = result.field;
        throw error;
      }
      const firstName = (form.elements.first_name?.value || '').trim().split(/\s+/)[0];
      success.querySelector('.success-name').textContent = firstName ? `, ${firstName}` : '';
      if (result.message) success.querySelector('.success-message').textContent = result.message;
      form.reset();
      counters.forEach((update) => update());
      form.hidden = true;
      success.hidden = false;
      success.focus();
      success.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
    } catch (error) {
      const text = error instanceof TypeError ? 'Please check your internet connection.' : error.message;
      status.textContent = '';
      if (flagField(error.field, text)) status.textContent = text;
      else showError(text);
    } finally {
      setBusy(false);
    }
  });
});
