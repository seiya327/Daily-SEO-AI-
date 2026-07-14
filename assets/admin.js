(function () {
  function activateTab(tab) {
    var target = tab.getAttribute('href');
    if (!target || target.charAt(0) !== '#') {
      return;
    }

    document.querySelectorAll('.dsap-tabs .nav-tab').forEach(function (item) {
      item.classList.toggle('nav-tab-active', item === tab);
      item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
    });

    document.querySelectorAll('.dsap-section').forEach(function (section) {
      section.classList.toggle('is-active', '#' + section.id === target);
    });

    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', target);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('.dsap-tabs .nav-tab');
    tabs.forEach(function (tab) {
      tab.setAttribute('role', 'tab');
      tab.addEventListener('click', function (event) {
        event.preventDefault();
        activateTab(tab);
      });
    });

    var initial = document.querySelector('.dsap-tabs .nav-tab[href="' + window.location.hash + '"]') || document.querySelector('.dsap-tabs .nav-tab');
    if (initial) {
      activateTab(initial);
    }

    var wrap = document.querySelector('.dsap-wrap[data-dsap-active-jobs="1"]');
    if (wrap) {
      var dirty = false;
      document.querySelectorAll('.dsap-wrap form').forEach(function (form) {
        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('change', function () { dirty = true; });
      });
      window.setInterval(function () {
        var hash = window.location.hash || '#dsap-initial-setup';
        var progressTab = hash === '#dsap-initial-setup' || hash === '#dsap-dashboard';
        if (progressTab && !dirty && !document.hidden) {
          window.location.reload();
        }
      }, 15000);
    }
  });
})();
