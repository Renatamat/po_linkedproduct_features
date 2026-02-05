document.addEventListener('DOMContentLoaded', () => {
  const selects = document.querySelectorAll('.type-select');

  selects.forEach((select) => {
    select.addEventListener('change', (event) => {
      const target = event.currentTarget;
      if (!(target instanceof HTMLSelectElement)) {
        return;
      }
      const url = target.value;
      if (!url) {
        return;
      }
      window.location.href = url;
    });
  });
});
