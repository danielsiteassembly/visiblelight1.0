console.log('✅ fadeout-effects.js is running');

document.addEventListener('DOMContentLoaded', function () {
  const target = document.getElementById('targetElement');
  const trigger = document.getElementById('triggerElement');

  if (!target || !trigger) {
    console.warn('❌ Elements not found');
    return;
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      target.classList.toggle('fade-out', entry.isIntersecting);
    });
  });

  observer.observe(trigger);
});
