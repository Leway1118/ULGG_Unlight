/* ULGG_SHELL_SINGLE_SOURCE_20261027 */
(() => {
  const openActive = () => {
    document.querySelectorAll('.un-sidebar .sidebar-group-items').forEach(group => {
      if (group.querySelector('.sidebar-link.is-active')) group.classList.add('open');
    });
  };
  openActive();
  document.querySelectorAll('.un-sidebar .sidebar-group-toggle').forEach(btn => {
    const target = document.getElementById(btn.dataset.target || '');
    if (!target) return;
    const sync = () => btn.setAttribute('aria-expanded', String(target.classList.contains('open')));
    sync();
    btn.addEventListener('click', () => {
      target.classList.toggle('open');
      sync();
    });
  });
})();
