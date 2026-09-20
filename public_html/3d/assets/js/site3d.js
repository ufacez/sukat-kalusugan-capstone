import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

/* ============================================================
   SUKAT KALUSUGAN — 3D KIOSK SHOWCASE
   ============================================================ */

const canvas = document.getElementById('hero-canvas');
const scene = new THREE.Scene();
scene.fog = new THREE.FogExp2(0x0a1a12, 0.035);

const camera = new THREE.PerspectiveCamera(45, window.innerWidth / window.innerHeight, 0.1, 100);
camera.position.set(8, 5, 12);

const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.setSize(window.innerWidth, window.innerHeight);

const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.dampingFactor = 0.06;
controls.enablePan = false;
controls.minDistance = 6;
controls.maxDistance = 20;
controls.maxPolarAngle = Math.PI * 0.55;
controls.autoRotate = true;
controls.autoRotateSpeed = 0.8;

/* ---------- Lights ---------- */
const ambient = new THREE.AmbientLight(0xffffff, 0.4);
scene.add(ambient);

const keyLight = new THREE.DirectionalLight(0xffffff, 1.2);
keyLight.position.set(5, 10, 7);
scene.add(keyLight);

const rimLight = new THREE.DirectionalLight(0x2ec57a, 1.5);
rimLight.position.set(-6, 4, -5);
scene.add(rimLight);

const pointLight = new THREE.PointLight(0x2ec57a, 1, 10);
pointLight.position.set(0, 3, 2);
scene.add(pointLight);

/* ---------- Materials ---------- */
const MAT = {
    body: new THREE.MeshStandardMaterial({ color: 0x07553d, metalness: 0.6, roughness: 0.35 }),
    bodyDark: new THREE.MeshStandardMaterial({ color: 0x05402e, metalness: 0.5, roughness: 0.4 }),
    screen: new THREE.MeshStandardMaterial({ color: 0x0b6e4f, emissive: 0x0b6e4f, emissiveIntensity: 0.8, metalness: 0.3, roughness: 0.5 }),
    screenGlow: new THREE.MeshBasicMaterial({ color: 0x2ec57a, transparent: true, opacity: 0.25 }),
    metal: new THREE.MeshStandardMaterial({ color: 0x1a3a2c, metalness: 0.8, roughness: 0.3 }),
    green: new THREE.MeshStandardMaterial({ color: 0x2ec57a, emissive: 0x2ec57a, emissiveIntensity: 0.5, metalness: 0.4, roughness: 0.4 }),
    white: new THREE.MeshStandardMaterial({ color: 0xffffff, metalness: 0.2, roughness: 0.4 }),
};

/* ---------- Group ---------- */
const kiosk = new THREE.Group();
scene.add(kiosk);

/* ---------- Main body (cabinet/podium) ---------- */
const body = new THREE.Mesh(new THREE.BoxGeometry(3.2, 4.4, 2.2), MAT.body);
body.position.y = 2.2;
kiosk.add(body);

/* ---------- Screen (front face) ---------- */
const screenMesh = new THREE.Mesh(new THREE.BoxGeometry(2.6, 2.8, 0.15), MAT.screen);
screenMesh.position.set(0, 3.1, 1.15);
kiosk.add(screenMesh);

const screenGlow = new THREE.Mesh(new THREE.PlaneGeometry(2.9, 3.1), MAT.screenGlow);
screenGlow.position.set(0, 3.1, 1.28);
screenGlow.rotation.y = 0;
kiosk.add(screenGlow);

/* ---------- Screen content (simple UI mockup) ---------- */
const uiGroup = new THREE.Group();
screenMesh.add(uiGroup);

const uiLogo = new THREE.Mesh(new THREE.CircleGeometry(0.5, 32), MAT.green);
uiLogo.position.set(0, 1.35, 0.08);
uiGroup.add(uiLogo);

const uiHeart = new THREE.Mesh(new THREE.CircleGeometry(0.3, 32), MAT.white);
uiHeart.position.set(0, 1.35, 0.09);
uiGroup.add(uiHeart);

const uiTitle = new THREE.Mesh(new THREE.PlaneGeometry(1.6, 0.12), MAT.white);
uiTitle.position.set(0, 1.05, 0.08);
uiGroup.add(uiTitle);

const uiButton = new THREE.Mesh(new THREE.PlaneGeometry(1.4, 0.28), MAT.green);
uiButton.position.set(0, 0.3, 0.08);
uiGroup.add(uiButton);

/* ---------- Side panel (ruler) ---------- */
const sidePanel = new THREE.Mesh(new THREE.BoxGeometry(0.1, 3.4, 2.0), MAT.bodyDark);
sidePanel.position.set(-1.65, 2.2, 0);
kiosk.add(sidePanel);

const ruler = new THREE.Group();
ruler.position.set(-1.72, 2.2, 0);
ruler.rotation.y = -Math.PI / 2;
kiosk.add(ruler);

for (let i = 0; i < 12; i++) {
    const tick = new THREE.Mesh(new THREE.BoxGeometry(0.08, 0.02, 0.02), MAT.white);
    tick.position.set(0, -1.4 + i * 0.25, 0.9);
    ruler.add(tick);
}

/* ---------- Base platform (scale) ---------- */
const base = new THREE.Mesh(new THREE.BoxGeometry(3.6, 0.25, 2.6), MAT.metal);
base.position.y = 0.125;
kiosk.add(base);

const platform = new THREE.Mesh(new THREE.BoxGeometry(2.8, 0.12, 2.0), MAT.bodyDark);
platform.position.y = 0.31;
kiosk.add(platform);

/* ---------- ESP32 badge (small box on side) ---------- */
const espBadge = new THREE.Mesh(new THREE.BoxGeometry(0.6, 0.4, 0.1), MAT.bodyDark);
espBadge.position.set(-1.66, 1.2, 0.4);
espBadge.rotation.y = -Math.PI / 2;
kiosk.add(espBadge);

const espLed = new THREE.Mesh(new THREE.SphereGeometry(0.04, 16, 16), MAT.green);
espLed.position.set(-1.72, 1.2, 0.4);
kiosk.add(espLed);

/* ---------- Top emblem (cylinder) ---------- */
const emblem = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.35, 0.12, 32), MAT.green);
emblem.position.y = 4.46;
kiosk.add(emblem);

/* ---------- Floating particles ---------- */
const particleCount = 120;
const particlePositions = new Float32Array(particleCount * 3);
for (let i = 0; i < particleCount; i++) {
    particlePositions[i * 3] = (Math.random() - 0.5) * 20;
    particlePositions[i * 3 + 1] = Math.random() * 10 - 2;
    particlePositions[i * 3 + 2] = (Math.random() - 0.5) * 20;
}
const particleGeo = new THREE.BufferGeometry();
particleGeo.setAttribute('position', new THREE.BufferAttribute(particlePositions, 3));
const particleMat = new THREE.PointsMaterial({ color: 0x2ec57a, size: 0.08, transparent: true, opacity: 0.5 });
const particles = new THREE.Points(particleGeo, particleMat);
scene.add(particles);

/* ---------- Ground grid ---------- */
const grid = new THREE.GridHelper(30, 30, 0x0b6e4f, 0x1e4a35);
grid.position.y = 0;
scene.add(grid);

/* ---------- Animation loop ---------- */
const clock = new THREE.Clock();
function animate() {
    requestAnimationFrame(animate);
    const t = clock.getElapsedTime();

    // Gentle kiosk bobbing
    kiosk.position.y = Math.sin(t * 0.8) * 0.05;

    // Screen pulse
    MAT.screen.emissiveIntensity = 0.7 + Math.sin(t * 2) * 0.15;
    MAT.screenGlow.opacity = 0.18 + Math.sin(t * 2) * 0.07;

    // Particle drift
    particles.rotation.y = t * 0.05;
    particles.position.y = Math.sin(t * 0.5) * 0.3;

    // ESP LED blink
    espLed.material.color.setHex(Math.sin(t * 3) > 0 ? 0x2ec57a : 0x0b6e4f);

    controls.update();
    renderer.render(scene, camera);
}
animate();

/* ---------- Resize ---------- */
window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
});

/* ---------- Scroll fade-in ---------- */
const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
        }
    });
}, { threshold: 0.1 });

document.querySelectorAll('.fade-in').forEach((el) => observer.observe(el));
