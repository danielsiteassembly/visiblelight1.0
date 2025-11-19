document.addEventListener('DOMContentLoaded', function () {
  const slider = document.getElementById('pricing-slider');
  if (!slider) return; // exit if the slider isn't on this page

  const arrowLeft = document.getElementById('arrow-left');
  const arrowRight = document.getElementById('arrow-right');
  const scrollAmount = 320;

  function updateArrows() {
    const atStart = slider.scrollLeft <= 0;
    const atEnd = slider.scrollLeft + slider.offsetWidth >= slider.scrollWidth - 1;
    arrowLeft.style.display = atStart ? 'none' : 'block';
    arrowRight.style.display = atEnd ? 'none' : 'block';
  }

  arrowRight.addEventListener('click', () => {
    slider.scrollBy({ left: scrollAmount, behavior: 'smooth' });
  });

  arrowLeft.addEventListener('click', () => {
    slider.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
  });

  slider.addEventListener('scroll', updateArrows);
  updateArrows();
});
