document.addEventListener('DOMContentLoaded', function () {
  var button = document.querySelector('.nav-toggle');
  var nav = document.querySelector('.site-nav');
  if (!button || !nav) return;

  function closeMenu() {
    button.setAttribute('aria-expanded', 'false');
    nav.classList.remove('is-open');
  }

  button.addEventListener('click', function () {
    var expanded = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    nav.classList.toggle('is-open');
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
    }
  });

  document.addEventListener('click', function (event) {
    if (!nav.classList.contains('is-open')) return;
    if (nav.contains(event.target) || button.contains(event.target)) return;
    closeMenu();
  });
});
