document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.type-select').forEach((select) => {
    select.addEventListener('change', (event) => {
      const target = event.currentTarget;
      if (!(target instanceof HTMLSelectElement)) {
        return;
      }
      const nextUrl = target.value;
      if (nextUrl) {
        window.location.href = nextUrl;
      }
    });
  });
});
