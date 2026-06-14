const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const observeElements = (selector, className, options = {}) => {
  const defaultOptions = { root: null, rootMargin: '0px 0px -80px 0px', threshold: 0.1, ...options };
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add(className);
        observer.unobserve(entry.target);
      }
    });
  }, defaultOptions);
  document.querySelectorAll(selector).forEach(el => observer.observe(el));
};

document.addEventListener('DOMContentLoaded', () => {
  const nav = document.querySelector('nav');
  if (nav && !prefersReducedMotion) {
    nav.style.opacity = '0'; nav.style.transform = 'translateY(-20px)';
    nav.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    requestAnimationFrame(() => { nav.style.opacity = '1'; nav.style.transform = 'translateY(0)'; });
  }
  const heroElements = document.querySelectorAll('.content-left h1, .content-left .tagline, .content-left .buttons, .content-right img');
  if (!prefersReducedMotion) {
    heroElements.forEach((el, index) => {
      el.style.opacity = '0'; el.style.transform = 'translateY(40px)';
      el.style.transition = `opacity 0.8s ease ${index * 0.14}s, transform 0.8s cubic-bezier(0.22, 0.61, 0.36, 1) ${index * 0.14}s`;
      requestAnimationFrame(() => { setTimeout(() => { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; }, 80); });
    });
  } else {
    heroElements.forEach(el => { el.style.opacity = '1'; el.style.transform = 'none'; });
  }
});

observeElements('.products-description .column', 'scroll-reveal-left');

const bentoCards = document.querySelectorAll('.grid-card');
if (!prefersReducedMotion) {
  const bentoObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const cardIndex = Array.from(bentoCards).indexOf(entry.target);
        setTimeout(() => entry.target.classList.add('scroll-reveal-up'), Math.max(0, cardIndex) * 110);
        bentoObserver.unobserve(entry.target);
      }
    });
  }, { root: null, rootMargin: '0px 0px -60px 0px', threshold: 0.1 });
  bentoCards.forEach(card => {
    card.style.opacity = '0'; card.style.transform = 'translateY(60px) scale(0.95)';
    card.style.transition = 'opacity 0.7s ease, transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1)';
    bentoObserver.observe(card);
  });
} else {
  bentoCards.forEach(card => { card.style.opacity = '1'; card.style.transform = 'none'; });
}

const pills = document.querySelectorAll('.bubble');
if (!prefersReducedMotion) {
  const pillObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const index = Array.from(pills).indexOf(entry.target);
        setTimeout(() => entry.target.classList.add('scroll-pop-in'), Math.max(0, index) * 90);
        pillObserver.unobserve(entry.target);
      }
    });
  }, { root: null, rootMargin: '0px 0px -40px 0px', threshold: 0.1 });
  pills.forEach(pill => {
    pill.style.opacity = '0'; pill.style.transform = 'scale(0) rotate(0deg)';
    pill.style.transition = 'opacity 0.5s ease, transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)';
    pillObserver.observe(pill);
  });
} else {
  pills.forEach(pill => { pill.style.opacity = '1'; pill.style.transform = 'none'; });
}

const bottleCards = document.querySelectorAll('.bottle-card');
if (!prefersReducedMotion) {
  const bottleObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const index = Array.from(bottleCards).indexOf(entry.target);
        setTimeout(() => entry.target.classList.add('scroll-reveal-up'), Math.max(0, index) * 160);
        bottleObserver.unobserve(entry.target);
      }
    });
  }, { root: null, rootMargin: '0px 0px -80px 0px', threshold: 0.15 });
  bottleCards.forEach(card => {
    card.style.opacity = '0'; card.style.transform = 'translateY(80px)';
    card.style.transition = 'opacity 0.8s ease, transform 0.8s cubic-bezier(0.22, 0.61, 0.36, 1)';
    bottleObserver.observe(card);
  });
} else {
  bottleCards.forEach(card => { card.style.opacity = '1'; card.style.transform = 'none'; });
}

let ticking = false;
const canImage = document.querySelector('.can-image');
const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
const handleParallax = () => {
  if (!canImage || prefersReducedMotion) { ticking = false; return; }
  const heroSection = document.querySelector('.container');
  if (!heroSection) { ticking = false; return; }
  const rect = heroSection.getBoundingClientRect();
  const viewH = window.innerHeight || document.documentElement.clientHeight;
  if (rect.bottom > 0 && rect.top < viewH) {
    const progress = clamp((-rect.top) / rect.height, 0, 1);
    canImage.style.transform = `translateY(${progress * 28}px) rotate(${progress * 5}deg)`;
  }
  ticking = false;
};
window.addEventListener('scroll', () => { if (!ticking) { requestAnimationFrame(handleParallax); ticking = true; } }, { passive: true });

const navbar = document.querySelector('nav');
window.addEventListener('scroll', () => {
  if (!navbar) return;
  const currentScroll = window.scrollY;
  if (currentScroll > 100) {
    navbar.style.background = 'rgba(165, 164, 164, 0.25)';
    navbar.style.backdropFilter = 'blur(20px)';
    navbar.style.boxShadow = '0 4px 30px rgba(0, 0, 0, 0.1)';
  } else {
    navbar.style.background = 'rgba(165, 164, 164, 0.093)';
    navbar.style.backdropFilter = 'blur(10px)';
    navbar.style.boxShadow = 'none';
  }
}, { passive: true });

document.querySelectorAll('nav a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function(e) {
    e.preventDefault();
    const target = document.querySelector(this.getAttribute('href'));
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

const progressBar = document.createElement('div');
progressBar.className = 'scroll-progress-bar';
progressBar.style.cssText = `position:fixed;top:0;left:0;width:0%;height:4px;background:linear-gradient(90deg,#22c1c3,#fdbb2d);z-index:9999;transition:width 0.1s linear;box-shadow:0 0 10px rgba(34,193,195,0.5);`;
document.body.appendChild(progressBar);
window.addEventListener('scroll', () => {
  const scrollTop = window.scrollY;
  const docHeight = document.documentElement.scrollHeight - window.innerHeight;
  progressBar.style.width = (docHeight > 0 ? (scrollTop / docHeight) * 100 : 0) + '%';
}, { passive: true });