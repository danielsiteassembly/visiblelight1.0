<script>
    const canvas = document.getElementById('particleCanvas');
    const ctx = canvas.getContext('2d');

    function resizeCanvas() {
      canvas.width = window.innerWidth;
      canvas.height = 600;
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    const particleCount = 1000;
    const particles = [];

    for (let i = 0; i < particleCount; i++) {
      particles.push({
        x: Math.random() * canvas.width,
        y: Math.random() * canvas.height,
        radius: 2 + Math.random() * 3,
        dx: (Math.random() - 0.5) * 0.2,
        dy: (Math.random() - 0.5) * 0.2,
        opacity: Math.random(),
        fade: Math.random() * 0.02,
      });
    }

    function animate() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      for (let p of particles) {
        // Update position
        p.x += p.dx;
        p.y += p.dy;

        // Float in place: reverse direction slightly if out of range
        if (p.x < 0 || p.x > canvas.width) p.dx *= -1;
        if (p.y < 0 || p.y > canvas.height) p.dy *= -1;

        // Fade in/out
        p.opacity += (Math.random() > 0.5 ? 1 : -1) * p.fade;
        p.opacity = Math.max(0, Math.min(1, p.opacity));

        // Draw particle
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2, false);
        ctx.fillStyle = `rgba(255, 255, 255, ${p.opacity})`;
        ctx.fill();
      }

      requestAnimationFrame(animate);
    }

    animate();
  </script>