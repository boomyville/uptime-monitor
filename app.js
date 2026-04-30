document.addEventListener('DOMContentLoaded', () => {
    initStarfield();
    initDashboard();
});

function initStarfield() {
    const canvas = document.createElement('canvas');
    canvas.className = 'starfield-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.prepend(canvas);

    const context = canvas.getContext('2d');
    const starCount = Math.min(170, Math.max(110, Math.floor(window.innerWidth / 10)));
    const stars = [];
    const mouse = { x: window.innerWidth / 2, y: window.innerHeight / 3, active: false };
    let width = 0;
    let height = 0;

    function resizeCanvas() {
        const ratio = window.devicePixelRatio || 1;
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = Math.floor(width * ratio);
        canvas.height = Math.floor(height * ratio);
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function createStar() {
        return {
            x: Math.random() * width,
            y: Math.random() * height,
            radius: Math.random() * 1.8 + 0.35,
            speedX: (Math.random() - 0.5) * 0.12,
            speedY: (Math.random() - 0.5) * 0.08,
            twinkle: Math.random() * Math.PI * 2,
            alpha: Math.random() * 0.55 + 0.2,
        };
    }

    function resetStars() {
        stars.length = 0;
        for (let index = 0; index < starCount; index += 1) {
            stars.push(createStar());
        }
    }

    function draw() {
        context.clearRect(0, 0, width, height);
        context.fillStyle = 'rgba(255, 255, 255, 0.92)';
        const driftX = mouse.active ? (mouse.x - width / 2) * 0.0003 : 0;
        const driftY = mouse.active ? (mouse.y - height / 2) * 0.0003 : 0;

        stars.forEach((star, index) => {
            const parallaxX = mouse.active ? (mouse.x - width / 2) * (0.0008 + star.radius * 0.0002) : 0;
            const parallaxY = mouse.active ? (mouse.y - height / 2) * (0.0008 + star.radius * 0.0002) : 0;
            star.x += star.speedX + driftX;
            star.y += star.speedY + driftY;
            star.twinkle += 0.02 + star.radius * 0.006;

            if (star.x > width + 12) star.x = -12;
            if (star.x < -12) star.x = width + 12;
            if (star.y > height + 12) star.y = -12;
            if (star.y < -12) star.y = height + 12;

            const pulse = (Math.sin(star.twinkle) + 1) / 2;
            const fade = pulse * pulse * (3 - 2 * pulse);
            const glow = 0.18 + fade * 0.52;
            const size = star.radius * (0.9 + fade * 0.22);
            const x = star.x + parallaxX;
            const y = star.y + parallaxY;

            context.beginPath();
            context.globalAlpha = Math.min(1, star.alpha + glow);
            context.shadowColor = 'rgba(34, 211, 238, 0.55)';
            context.shadowBlur = 6;
            context.arc(x, y, size, 0, Math.PI * 2);
            context.fill();
            context.shadowBlur = 0;

            for (let otherIndex = index + 1; otherIndex < stars.length; otherIndex += 1) {
                const other = stars[otherIndex];
                const otherX = other.x + (mouse.active ? (mouse.x - width / 2) * (0.0008 + other.radius * 0.0002) : 0);
                const otherY = other.y + (mouse.active ? (mouse.y - height / 2) * (0.0008 + other.radius * 0.0002) : 0);
                const distance = Math.hypot(otherX - x, otherY - y);
                if (distance < 120) {
                    context.beginPath();
                    context.globalAlpha = (1 - distance / 120) * 0.08;
                    context.strokeStyle = 'rgba(34, 211, 238, 1)';
                    context.lineWidth = 1;
                    context.moveTo(x, y);
                    context.lineTo(otherX, otherY);
                    context.stroke();
                }
            }
        });

        context.globalAlpha = 1;
        requestAnimationFrame(draw);
    }

    resizeCanvas();
    resetStars();
    draw();

    window.addEventListener('resize', () => {
        resizeCanvas();
        resetStars();
    });

    window.addEventListener('mousemove', (event) => {
        mouse.x = event.clientX;
        mouse.y = event.clientY;
        mouse.active = true;
    });

    window.addEventListener('mouseleave', () => {
        mouse.active = false;
    });
}

function initDashboard() {
    const chartModal = document.getElementById('chartModal');
    if (!chartModal || typeof ApexCharts === 'undefined') {
        return;
    }

    let myChart;

    window.showChart = function showChart(id, name) {
        const modal = document.getElementById('chartModal');
        const modalTitle = document.getElementById('modalTitle');
        const chartContainer = document.getElementById('chartContainer');
        if (!modal || !modalTitle || !chartContainer) {
            return;
        }

        modalTitle.innerText = 'Latency & Health - ' + name;
        fetch('index.php?get_stats=' + id)
            .then((response) => response.json())
            .then((data) => {
                const labels = data.map((item) => new Date(item.checked_at).toLocaleString());
                const values = data.map((item) => Number(item.cumulative_time || item.response_time || 0));
                const statuses = data.map((item) => item.health_status || 'unknown');

                const greenData = values.map((value, index) => (statuses[index] === 'green' ? value : null));
                const yellowData = values.map((value, index) => (statuses[index] === 'yellow' ? value : null));
                const redData = values.map((value, index) => (statuses[index] === 'red' ? value : null));

                if (myChart) {
                    myChart.destroy();
                }

                chartContainer.innerHTML = '';
                myChart = new ApexCharts(chartContainer, {
                    chart: {
                        type: 'line',
                        height: 360,
                        toolbar: { show: false },
                        fontFamily: 'Trebuchet MS, Trebuchet, Arial, sans-serif',
                        foreColor: '#e5e7eb',
                        background: 'transparent',
                    },
                    series: [
                        { name: 'Success (ms)', data: greenData },
                        { name: 'Recovered (ms)', data: yellowData },
                        { name: 'Failed (ms)', data: redData },
                    ],
                    stroke: { curve: 'smooth', width: 3 },
                    colors: ['#34d399', '#fbbf24', '#fb7185'],
                    grid: { borderColor: 'rgba(148, 163, 184, 0.16)' },
                    xaxis: {
                        categories: labels,
                        labels: {
                            rotate: -45,
                            hideOverlappingLabels: true,
                            style: { colors: '#cbd5e1' },
                        },
                    },
                    yaxis: {
                        labels: {
                            formatter: (value) => Math.round(value),
                            style: { colors: '#cbd5e1' },
                        },
                    },
                    tooltip: {
                        theme: 'dark',
                        fillSeriesColor: false,
                        style: { fontSize: '12px' },
                        y: {
                            formatter: (value, { dataPointIndex }) => {
                                const attempts = data[dataPointIndex]?.total_attempts || 1;
                                return value ? `${value.toFixed(2)} ms (${attempts} attempt${attempts > 1 ? 's' : ''})` : '—';
                            },
                        },
                    },
                    legend: { position: 'top' },
                    plotOptions: { scatter: { size: 8 } },
                });
                myChart.render();
                modal.classList.add('is-open');
            });
    };

    window.closeChart = function closeChart() {
        const modal = document.getElementById('chartModal');
        if (modal) {
            modal.classList.remove('is-open');
        }
        if (myChart) {
            myChart.destroy();
            myChart = null;
        }
    };

    window.openDeleteModal = function openDeleteModal(id, name) {
        const deleteSiteId = document.getElementById('deleteSiteId');
        const deleteMessage = document.getElementById('deleteMessage');
        const deleteModal = document.getElementById('deleteModal');
        if (!deleteSiteId || !deleteMessage || !deleteModal) {
            return;
        }
        deleteSiteId.value = id;
        deleteMessage.innerText = `This will permanently delete ${name} and all of its logs.`;
        deleteModal.classList.add('is-open');
    };

    window.closeDeleteModal = function closeDeleteModal() {
        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.classList.remove('is-open');
        }
    };

    window.openSettingsModal = function openSettingsModal(id, name, timeout, retries) {
        const settingsSiteId = document.getElementById('settingsSiteId');
        const settingsTitle = document.getElementById('settingsTitle');
        const settingsTimeout = document.getElementById('settingsTimeout');
        const settingsRetries = document.getElementById('settingsRetries');
        const settingsModal = document.getElementById('settingsModal');
        if (!settingsSiteId || !settingsTitle || !settingsTimeout || !settingsRetries || !settingsModal) {
            return;
        }
        settingsSiteId.value = id;
        settingsTitle.innerText = `Settings for ${name}`;
        settingsTimeout.value = timeout;
        settingsRetries.value = retries;
        settingsModal.classList.add('is-open');
    };

    window.closeSettingsModal = function closeSettingsModal() {
        const settingsModal = document.getElementById('settingsModal');
        if (settingsModal) {
            settingsModal.classList.remove('is-open');
        }
    };

    chartModal.addEventListener('click', (event) => {
        if (event.target.id === 'chartModal') {
            closeChart();
        }
    });

    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) {
        deleteModal.addEventListener('click', (event) => {
            if (event.target.id === 'deleteModal') {
                closeDeleteModal();
            }
        });
    }

    const settingsModal = document.getElementById('settingsModal');
    if (settingsModal) {
        settingsModal.addEventListener('click', (event) => {
            if (event.target.id === 'settingsModal') {
                closeSettingsModal();
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeChart();
            closeDeleteModal();
            closeSettingsModal();
        }
    });
}
