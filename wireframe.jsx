import { useState, useEffect, useMemo, useRef } from "react";

// ─── Design Tokens ────────────────────────────────────────────────────────────
const T = {
  primary: "#0B6E4F",
  primaryLight: "#E6F4EF",
  primaryMid: "#1A8F68",
  accent: "#F5A623",
  accentLight: "#FFF8EC",
  danger: "#E03131",
  dangerLight: "#FFF0F0",
  warn: "#E67E22",
  warnLight: "#FFF4E5",
  info: "#1971C2",
  infoLight: "#E7F5FF",
  purple: "#7048E8",
  purpleLight: "#F3F0FF",
  bg: "#F5F7F6",
  card: "#FFFFFF",
  border: "#E2E8E5",
  text: "#1A2B25",
  textMuted: "#6B8C7D",
  textLight: "#9BB5AC",
  sidebar: "#0D2B20",
  sidebarHover: "#163D2E",
  sidebarActive: "#1A8F68",
};

const APP_FONT =
  "Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";

// ─── Mock Data ────────────────────────────────────────────────────────────────
const INIT_CHILDREN = [
  {
    id: 1,
    child_code: "CHD-0001",
    first_name: "Maria",
    last_name: "Santos",
    birthdate: "2022-03-15",
    sex: "Female",
    age_months: 26,
    barangay: "Bagong Silang",
    address: "123 Rizal St.",
    parent: "Ana Santos",
    status: "Normal",
  },
  {
    id: 2,
    child_code: "CHD-0002",
    first_name: "Juan",
    last_name: "Dela Cruz",
    birthdate: "2022-09-20",
    sex: "Male",
    age_months: 20,
    barangay: "Poblacion",
    address: "45 Mabini Ave.",
    parent: "Rosa Dela Cruz",
    status: "Underweight",
  },
  {
    id: 3,
    child_code: "CHD-0003",
    first_name: "Lucia",
    last_name: "Reyes",
    birthdate: "2021-12-01",
    sex: "Female",
    age_months: 29,
    barangay: "San Jose",
    address: "78 Luna St.",
    parent: "Carla Reyes",
    status: "Stunted",
  },
  {
    id: 4,
    child_code: "CHD-0004",
    first_name: "Miguel",
    last_name: "Torres",
    birthdate: "2023-06-10",
    sex: "Male",
    age_months: 11,
    barangay: "Bagong Silang",
    address: "9 Bonifacio Rd.",
    parent: "Pedro Torres",
    status: "Normal",
  },
  {
    id: 5,
    child_code: "CHD-0005",
    first_name: "Sofia",
    last_name: "Garcia",
    birthdate: "2022-01-05",
    sex: "Female",
    age_months: 28,
    barangay: "Sta. Cruz",
    address: "32 Aguinaldo St.",
    parent: "Lena Garcia",
    status: "Severely Underweight",
  },
  {
    id: 6,
    child_code: "CHD-0006",
    first_name: "Carlos",
    last_name: "Lim",
    birthdate: "2023-01-22",
    sex: "Male",
    age_months: 16,
    barangay: "Poblacion",
    address: "55 Del Pilar St.",
    parent: "Mei Lim",
    status: "Wasted",
  },
  {
    id: 7,
    child_code: "CHD-0007",
    first_name: "Isabella",
    last_name: "Ramos",
    birthdate: "2022-07-30",
    sex: "Female",
    age_months: 22,
    barangay: "San Jose",
    address: "101 Burgos Ave.",
    parent: "Gloria Ramos",
    status: "Normal",
  },
  {
    id: 8,
    child_code: "CHD-0008",
    first_name: "Andres",
    last_name: "Cruz",
    birthdate: "2021-05-14",
    sex: "Male",
    age_months: 36,
    barangay: "Sta. Cruz",
    address: "22 Rizal Blvd.",
    parent: "Nena Cruz",
    status: "Overweight",
  },
];

const INIT_MEASUREMENTS = [
  {
    id: 1,
    child_id: 1,
    child: "Maria Santos",
    height_cm: 85.2,
    weight_kg: 11.8,
    age_months: 26,
    measurement_date: "2024-05-10",
    source_type: "kiosk",
    nutritional_status: "Normal",
    waz: 0.4,
    haz: 0.2,
    whz: 0.5,
  },
  {
    id: 2,
    child_id: 2,
    child: "Juan Dela Cruz",
    height_cm: 76.1,
    weight_kg: 8.2,
    age_months: 20,
    measurement_date: "2024-05-09",
    source_type: "mobile",
    nutritional_status: "Underweight",
    waz: -2.1,
    haz: -1.3,
    whz: -1.8,
  },
  {
    id: 3,
    child_id: 3,
    child: "Lucia Reyes",
    height_cm: 78.3,
    weight_kg: 10.2,
    age_months: 29,
    measurement_date: "2024-05-08",
    source_type: "kiosk",
    nutritional_status: "Stunted",
    waz: -1.2,
    haz: -2.4,
    whz: -0.6,
  },
  {
    id: 4,
    child_id: 5,
    child: "Sofia Garcia",
    height_cm: 80.0,
    weight_kg: 7.9,
    age_months: 28,
    measurement_date: "2024-05-07",
    source_type: "manual",
    nutritional_status: "Severely Underweight",
    waz: -3.2,
    haz: -1.5,
    whz: -3.1,
  },
  {
    id: 5,
    child_id: 6,
    child: "Carlos Lim",
    height_cm: 72.5,
    weight_kg: 7.1,
    age_months: 16,
    measurement_date: "2024-05-06",
    source_type: "kiosk",
    nutritional_status: "Wasted",
    waz: -1.8,
    haz: -1.0,
    whz: -2.2,
  },
  {
    id: 6,
    child_id: 8,
    child: "Andres Cruz",
    height_cm: 92.0,
    weight_kg: 16.5,
    age_months: 36,
    measurement_date: "2024-05-05",
    source_type: "mobile",
    nutritional_status: "Overweight",
    waz: 2.2,
    haz: 0.8,
    whz: 2.5,
  },
];

const INIT_PARENTS = [
  {
    id: 1,
    name: "Ana Santos",
    email: "ana.santos@email.com",
    phone: "09171234567",
    children: 1,
    status: "Active",
  },
  {
    id: 2,
    name: "Rosa Dela Cruz",
    email: "rosa.dc@email.com",
    phone: "09281234567",
    children: 1,
    status: "Active",
  },
  {
    id: 3,
    name: "Carla Reyes",
    email: "carla.reyes@email.com",
    phone: "09391234567",
    children: 1,
    status: "Active",
  },
  {
    id: 4,
    name: "Pedro Torres",
    email: "pedro.torres@email.com",
    phone: "09451234567",
    children: 1,
    status: "Active",
  },
  {
    id: 5,
    name: "Lena Garcia",
    email: "lena.garcia@email.com",
    phone: "09561234567",
    children: 1,
    status: "Inactive",
  },
];

const INIT_USERS = [
  {
    id: 1,
    name: "John Doe",
    email: "admin@sukat.ph",
    username: "johndoe12",
    phone: "0813-2222-8899",
    role: "admin",
    status: "Active",
    date_created: "27 Mar 2024 18:45",
    last_login: "2024-05-10 08:30",
    barangay: "All",
  },
  {
    id: 2,
    name: "Dr. Maria Santos",
    email: "nutritionist@sukat.ph",
    username: "mariasantos",
    phone: "0813-4729-1056",
    role: "nutritionist",
    status: "Active",
    date_created: "26 Mar 2024 14:22",
    last_login: "2024-05-10 09:15",
    barangay: "Bagong Silang",
  },
  {
    id: 3,
    name: "Nurse Cynthia Reyes",
    email: "cynthia@sukat.ph",
    username: "cynthiareyes",
    phone: "0871-0394-7682",
    role: "nutritionist",
    status: "Active",
    date_created: "25 Mar 2024 09:57",
    last_login: "2024-05-09 14:22",
    barangay: "Poblacion",
  },
  {
    id: 4,
    name: "Dr. Jose Garcia",
    email: "jose@sukat.ph",
    username: "josegarcia",
    phone: "0812-5583-9217",
    role: "nutritionist",
    status: "Inactive",
    date_created: "24 Mar 2024 20:10",
    last_login: "2024-04-28 11:00",
    barangay: "San Jose",
  },
  {
    id: 5,
    name: "Abizar Alghifary",
    email: "abizar@sukat.ph",
    username: "abizar33",
    phone: "0813-4729-1056",
    role: "nutritionist",
    status: "Inactive",
    date_created: "26 Mar 2024 14:22",
    last_login: "2024-05-10 09:15",
    barangay: "Bagong Silang",
  },
  {
    id: 6,
    name: "Putri Amaliah",
    email: "putri@sukat.ph",
    username: "putri211099",
    phone: "0812-5583-9217",
    role: "nutritionist",
    status: "Active",
    date_created: "24 Mar 2024 20:10",
    last_login: "2024-05-09 14:22",
    barangay: "Poblacion",
  },
];

const INIT_AUDIT_LOGS = [
  {
    id: 1,
    user: "admin@sukat.ph",
    action: "LOGIN",
    detail: "Admin login from 192.168.1.10",
    timestamp: "2024-05-10 08:30:12",
    level: "info",
  },
  {
    id: 2,
    user: "nutritionist@sukat.ph",
    action: "ADD_MEASUREMENT",
    detail: "Added measurement for CHD-0001 (Maria Santos)",
    timestamp: "2024-05-10 09:18:44",
    level: "info",
  },
  {
    id: 3,
    user: "nutritionist@sukat.ph",
    action: "LOGIN",
    detail: "Nutritionist login from 192.168.1.15",
    timestamp: "2024-05-10 09:15:05",
    level: "info",
  },
  {
    id: 4,
    user: "KIOSK-01",
    action: "KIOSK_MEASURE",
    detail: "Measurement taken: CHD-0002, H=76.1cm W=8.2kg",
    timestamp: "2024-05-09 10:22:33",
    level: "info",
  },
  {
    id: 5,
    user: "admin@sukat.ph",
    action: "CALIBRATE_SENSOR",
    detail: "HX711 load cell calibrated. Factor: 2280.5",
    timestamp: "2024-05-09 08:05:17",
    level: "warn",
  },
  {
    id: 6,
    user: "admin@sukat.ph",
    action: "DELETE_USER",
    detail: "Deactivated user jose@sukat.ph",
    timestamp: "2024-05-08 16:40:09",
    level: "danger",
  },
  {
    id: 7,
    user: "KIOSK-01",
    action: "SENSOR_ERROR",
    detail: "TF-Luna LiDAR timeout – retried 3x",
    timestamp: "2024-05-08 11:15:02",
    level: "danger",
  },
  {
    id: 8,
    user: "nutritionist@sukat.ph",
    action: "EXPORT_REPORT",
    detail: "Exported malnutrition report (May 2024)",
    timestamp: "2024-05-07 15:00:41",
    level: "info",
  },
];

const INIT_APPOINTMENTS = [
  {
    id: 1,
    child: "Maria Santos",
    parent: "Ana Santos",
    date: "2024-05-15",
    time: "09:00",
    type: "Follow-up",
    status: "Scheduled",
    note: "Monthly growth check",
  },
  {
    id: 2,
    child: "Juan Dela Cruz",
    parent: "Rosa Dela Cruz",
    date: "2024-05-16",
    time: "10:30",
    type: "Nutrition Counseling",
    status: "Scheduled",
    note: "Underweight case – dietary plan",
  },
  {
    id: 3,
    child: "Sofia Garcia",
    parent: "Lena Garcia",
    date: "2024-05-14",
    time: "08:00",
    type: "Emergency",
    status: "Completed",
    note: "Severely underweight intervention",
  },
  {
    id: 4,
    child: "Lucia Reyes",
    parent: "Carla Reyes",
    date: "2024-05-20",
    time: "14:00",
    type: "Follow-up",
    status: "Scheduled",
    note: "Stunting monitoring",
  },
];

// ─── WHO Z-Score ──────────────────────────────────────────────────────────────
function computeWHO({ weight_kg, height_cm, age_months }) {
  const wazRef = { median: 9.5 + age_months * 0.15, sd: 1.2 };
  const hazRef = { median: 65 + age_months * 0.9, sd: 3.2 };
  const whzRef = { median: 10.5 + (height_cm - 65) * 0.09, sd: 1.1 };
  const waz = ((weight_kg - wazRef.median) / wazRef.sd).toFixed(2);
  const haz = ((height_cm - hazRef.median) / hazRef.sd).toFixed(2);
  const whz = ((weight_kg - whzRef.median) / whzRef.sd).toFixed(2);
  let status = "Normal";
  if (parseFloat(waz) < -3 || parseFloat(whz) < -3)
    status = "Severely Underweight";
  else if (parseFloat(waz) < -2) status = "Underweight";
  else if (parseFloat(haz) < -2) status = "Stunted";
  else if (parseFloat(whz) < -2) status = "Wasted";
  else if (parseFloat(whz) > 2) status = "Overweight";
  return {
    waz: parseFloat(waz),
    haz: parseFloat(haz),
    whz: parseFloat(whz),
    status,
  };
}

// ─── Status Helpers ───────────────────────────────────────────────────────────
const STATUS_COLOR = {
  Normal: T.primary,
  Underweight: T.warn,
  "Severely Underweight": T.danger,
  Stunted: T.purple,
  Wasted: T.info,
  Overweight: T.accent,
};
const STATUS_BG = {
  Normal: T.primaryLight,
  Underweight: T.warnLight,
  "Severely Underweight": T.dangerLight,
  Stunted: T.purpleLight,
  Wasted: T.infoLight,
  Overweight: T.accentLight,
};
const sColor = (s) => STATUS_COLOR[s] || T.textMuted;
const sBg = (s) => STATUS_BG[s] || "#f5f5f5";

const StatusBadge = ({ status }) => (
  <span
    style={{
      background: sBg(status),
      color: sColor(status),
      fontSize: 11,
      fontWeight: 700,
      padding: "3px 10px",
      borderRadius: 20,
      display: "inline-block",
      letterSpacing: 0.3,
      whiteSpace: "nowrap",
    }}
  >
    {status}
  </span>
);

// ─── Inline SVG Icons ───────────────────────────────────────────────────────
// Generic icon set via path data
const PATHS = {
  dashboard: "M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z",
  children:
    "M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75",
  measurements:
    "M3 3h18v18H3zM3 9h4M3 15h4M9 3v4M15 3v4M21 9h-4M21 15h-4M9 21v-4M15 21v-4",
  parents:
    "M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8",
  reports: "M18 20V10M12 20V4M6 20v-6",
  settings:
    "M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z",
  logout: "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9",
  kiosk: "M2 3h20v14H2zM8 21h8M12 17v4",
  sensor:
    "M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2zM9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0M15 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5",
  audit:
    "M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M16 13H8M16 17H8M10 9H8",
  users:
    "M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75M9 7a4 4 0 1 0 0-8 4 4 0 0 0 0 8",
  heart:
    "M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z",
  bell: "M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0",
  activity: "M22 12h-4l-3 9L9 3l-3 9H2",
  plus: "M12 5v14M5 12h14",
  edit: "M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z",
  trash:
    "M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2",
  x: "M18 6 6 18M6 6l12 12",
  check: "M20 6 9 17l-5-5",
  search: "M21 21l-4.35-4.35M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z",
  eye: "M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6z",
  calendar: "M3 4h18v18H3zM16 2v4M8 2v4M3 10h18",
  wifi: "M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01",
  cpu: "M12 12H4V4h8v8zM20 4h-4v4h4V4zM20 12h-4v4h4v-4zM4 16H2M4 12H2M4 8H2M12 20v2M8 20v2M16 20v2M20 20v2",
  zap: "M13 2 3 14h9l-1 8 10-12h-9l1-8z",
  arrowLeft: "M19 12H5M12 5l-7 7 7 7",
  arrowRight: "M5 12h14M12 5l7 7-7 7",
  mail: "M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM22 6l-10 7L2 6",
  phone:
    "M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.63 3.42 2 2 0 0 1 3.6 1.24h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z",
  scan: "M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10",
  ruler: "M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3zM13 13l6 6",
  scale: "M12 3v2M3 12h2M19 12h2M12 17l-5 2V12a5 5 0 0 1 10 0v7l-5-2z",
  save: "M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2zM17 21v-8H7v8M7 3v5h8",
  trendUp: "M23 6l-9.5 9.5-5-5L1 18M17 6h6v6",
  alertTriangle:
    "M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01",
  info: "M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 16v-4M12 8h.01",
  rolesIcon:
    "M12 2a3 3 0 0 0 0 6 3 3 0 0 0 0-6zM5 12a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19 12a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM12 8v4M5 15l5.5 3M18.5 15 13 18",
  stethoscope:
    "M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3M14 9a6 6 0 0 0 6 6h0a2.5 2.5 0 0 1 0 5h0",
  childFace:
    "M12 2a7 7 0 0 1 7 7c0 3-1.5 5.5-4 6.7V18a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2v-2.3C6.5 14.5 5 12 5 9a7 7 0 0 1 7-7zM9 10h.01M15 10h.01M9.5 14s1 1.5 2.5 1.5 2.5-1.5 2.5-1.5",
  girlFace:
    "M12 2a7 7 0 0 1 7 7c0 3-1.5 5.5-4 6.7V18a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2v-2.3C6.5 14.5 5 12 5 9a7 7 0 0 1 7-7zM9 10h.01M15 10h.01M9.5 14s1 1.5 2.5 1.5 2.5-1.5 2.5-1.5M8 4.5c0 0 1.5-2 4-2s4 2 4 2",
  wave: "M2 12c1-4 2-4 3 0s2 4 3 0 2-4 3 0 2 4 3 0",
  checkCircle: "M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3",
  signal: "M2 20h.01M7 20v-4M12 20V8M17 20V4M22 20v-8",
  lock: "M19 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2zM17 11V7a5 5 0 0 0-10 0v4",
  hospital:
    "M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10M12 6v6M9 9h6",
  user: "M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8",
  layers: "M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5",
  compass:
    "M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM16.24 7.76l-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12z",
};

const Icon = ({ name, size = 16, color = "currentColor", style = {} }) => {
  const d = PATHS[name];
  if (!d) return null;
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke={color}
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      style={style}
    >
      <path d={d} />
    </svg>
  );
};

// ─── Child Avatar SVG ────────────────────────────────────────────────────────
const ChildAvatar = ({ sex, size = 32 }) => {
  const isFemale = sex === "Female";
  const bg = isFemale ? "#FCE4EC" : "#E3F2FD";
  const hairColor = isFemale ? "#5D4037" : "#3E2723";
  const skinColor = "#FFCC99";
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 40 40"
      fill="none"
      style={{ borderRadius: "50%", background: bg, flexShrink: 0 }}
    >
      {/* Body */}
      <ellipse
        cx="20"
        cy="34"
        rx="9"
        ry="6"
        fill={isFemale ? "#E91E63" : "#1565C0"}
        opacity="0.7"
      />
      {/* Head */}
      <circle cx="20" cy="18" r="9" fill={skinColor} />
      {/* Hair */}
      {isFemale ? (
        <>
          <ellipse cx="20" cy="11" rx="9" ry="5" fill={hairColor} />
          <rect x="11" y="11" width="3" height="8" rx="1.5" fill={hairColor} />
          <rect x="26" y="11" width="3" height="8" rx="1.5" fill={hairColor} />
        </>
      ) : (
        <ellipse cx="20" cy="11" rx="9" ry="4" fill={hairColor} />
      )}
      {/* Eyes */}
      <circle cx="16.5" cy="18" r="1.2" fill="#333" />
      <circle cx="23.5" cy="18" r="1.2" fill="#333" />
      {/* Smile */}
      <path
        d="M17 22 Q20 24.5 23 22"
        stroke="#A0522D"
        strokeWidth="1.2"
        strokeLinecap="round"
        fill="none"
      />
    </svg>
  );
};

const SectionHead = ({ title, desc, icon, color }) => (
  <div
    style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 20 }}
  >
    <div
      style={{
        width: 40,
        height: 40,
        borderRadius: 12,
        background: color + "18",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      <Icon name={icon} size={20} color={color} />
    </div>
    <div>
      <div style={{ fontWeight: 700, fontSize: 15, color: T.text }}>
        {title}
      </div>
      <div style={{ fontSize: 12, color: T.textMuted }}>{desc}</div>
    </div>
  </div>
);

const KioskLogo = () => (
  <svg width={100} height={100} viewBox="0 0 100 100" fill="none">
    <circle
      cx="50"
      cy="50"
      r="48"
      fill="rgba(43,200,138,0.12)"
      stroke="rgba(43,200,138,0.3)"
      strokeWidth="1"
    />
    <path
      d="M50 68 L22 44 A18 18 0 0 1 50 30 A18 18 0 0 1 78 44 Z"
      fill="none"
      stroke="#2BC88A"
      strokeWidth="2.5"
      strokeLinejoin="round"
    />
    <path
      d="M50 30 Q50 42 50 52"
      stroke="#2BC88A"
      strokeWidth="2"
      strokeLinecap="round"
      opacity="0.5"
    />
    <rect
      x="46"
      y="38"
      width="8"
      height="22"
      rx="2"
      fill="#2BC88A"
      opacity="0.8"
    />
    <rect
      x="39"
      y="45"
      width="22"
      height="8"
      rx="2"
      fill="#2BC88A"
      opacity="0.8"
    />
  </svg>
);

const ResultIcon = ({ isNormal }) => (
  <svg width={56} height={56} viewBox="0 0 56 56" fill="none">
    <circle
      cx="28"
      cy="28"
      r="26"
      fill={isNormal ? "rgba(43,200,138,0.15)" : "rgba(224,49,49,0.15)"}
      stroke={isNormal ? "#2BC88A" : T.danger}
      strokeWidth="2"
    />
    {isNormal ? (
      <path
        d="M18 28 L24 34 L38 20"
        stroke="#2BC88A"
        strokeWidth="3"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    ) : (
      <path
        d="M20 20 L36 36 M36 20 L20 36"
        stroke={T.danger}
        strokeWidth="3"
        strokeLinecap="round"
      />
    )}
  </svg>
);

// ─── Toast ────────────────────────────────────────────────────────────────────
function Toast({ msg, type, onClose }) {
  useEffect(() => {
    const t = setTimeout(onClose, 2800);
    return () => clearTimeout(t);
  });
  const bg =
    type === "success" ? T.primaryMid : type === "danger" ? T.danger : T.warn;
  return (
    <div
      style={{
        position: "fixed",
        bottom: 28,
        right: 28,
        zIndex: 9999,
        background: bg,
        color: "#fff",
        padding: "12px 20px",
        borderRadius: 12,
        fontSize: 13,
        fontWeight: 600,
        boxShadow: "0 8px 24px rgba(0,0,0,0.18)",
        display: "flex",
        alignItems: "center",
        gap: 10,
        minWidth: 240,
      }}
    >
      <Icon
        name={type === "success" ? "check" : "alertTriangle"}
        size={15}
        color="#fff"
      />
      {msg}
    </div>
  );
}

// ─── Confirm Dialog ───────────────────────────────────────────────────────────
function ConfirmDialog({ msg, onConfirm, onCancel }) {
  return (
    <div
      style={{
        position: "fixed",
        inset: 0,
        background: "rgba(10,30,20,0.5)",
        zIndex: 5000,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          padding: 28,
          width: 340,
          boxShadow: "0 24px 60px rgba(0,0,0,0.2)",
          border: `1px solid ${T.border}`,
        }}
      >
        <div
          style={{
            display: "flex",
            alignItems: "center",
            gap: 12,
            marginBottom: 14,
          }}
        >
          <div
            style={{
              width: 36,
              height: 36,
              borderRadius: "50%",
              background: T.dangerLight,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
            }}
          >
            <Icon name="alertTriangle" size={18} color={T.danger} />
          </div>
          <div style={{ fontWeight: 700, fontSize: 15, color: T.text }}>
            Confirm Delete
          </div>
        </div>
        <p
          style={{
            fontSize: 13,
            color: T.textMuted,
            marginBottom: 20,
            lineHeight: 1.5,
          }}
        >
          {msg}
        </p>
        <div style={{ display: "flex", gap: 10, justifyContent: "flex-end" }}>
          <button
            onClick={onCancel}
            style={{
              padding: "8px 18px",
              borderRadius: 8,
              border: `1px solid ${T.border}`,
              background: T.bg,
              color: T.textMuted,
              fontSize: 13,
              cursor: "pointer",
            }}
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            style={{
              padding: "8px 18px",
              borderRadius: 8,
              border: "none",
              background: T.danger,
              color: "#fff",
              fontSize: 13,
              fontWeight: 600,
              cursor: "pointer",
            }}
          >
            Delete
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Modal Shell ──────────────────────────────────────────────────────────────
function Modal({ title, onClose, children, width = 540 }) {
  return (
    <div
      style={{
        position: "fixed",
        inset: 0,
        background: "rgba(10,30,20,0.55)",
        backdropFilter: "blur(4px)",
        zIndex: 2000,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        padding: 24,
      }}
      onClick={onClose}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          background: T.card,
          borderRadius: 20,
          border: `1px solid ${T.border}`,
          width: "100%",
          maxWidth: width,
          maxHeight: "90vh",
          display: "flex",
          flexDirection: "column",
          boxShadow: "0 24px 60px rgba(0,0,0,0.18)",
          overflow: "hidden",
        }}
      >
        <div
          style={{
            padding: "18px 24px",
            borderBottom: `1px solid ${T.border}`,
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
            background: T.bg,
          }}
        >
          <div style={{ fontWeight: 700, fontSize: 15, color: T.text }}>
            {title}
          </div>
          <button
            onClick={onClose}
            style={{
              background: T.border,
              border: "none",
              borderRadius: 8,
              width: 30,
              height: 30,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              cursor: "pointer",
            }}
          >
            <Icon name="x" size={14} color={T.textMuted} />
          </button>
        </div>
        <div style={{ overflowY: "auto", flex: 1 }}>{children}</div>
      </div>
    </div>
  );
}

// ─── Form Helpers ─────────────────────────────────────────────────────────────
function Field({ label, children, required }) {
  return (
    <div style={{ marginBottom: 14 }}>
      <label
        style={{
          display: "block",
          fontSize: 11,
          fontWeight: 600,
          color: T.textMuted,
          marginBottom: 5,
          letterSpacing: 0.4,
        }}
      >
        {label}
        {required && <span style={{ color: T.danger, marginLeft: 2 }}>*</span>}
      </label>
      {children}
    </div>
  );
}
const inputS = {
  width: "100%",
  padding: "9px 12px",
  border: `1px solid ${T.border}`,
  borderRadius: 8,
  fontSize: 13,
  color: T.text,
  outline: "none",
  background: "#fff",
  boxSizing: "border-box",
};
const selectS = { ...inputS, cursor: "pointer" };
const btnPrimary = {
  background: T.primary,
  color: "#fff",
  border: "none",
  borderRadius: 10,
  padding: "10px 20px",
  fontSize: 13,
  fontWeight: 600,
  cursor: "pointer",
  display: "flex",
  alignItems: "center",
  gap: 6,
};
const btnSecondary = {
  padding: "9px 20px",
  borderRadius: 8,
  border: `1px solid ${T.border}`,
  background: T.bg,
  color: T.textMuted,
  fontSize: 13,
  cursor: "pointer",
};

// ─── Page Header ──────────────────────────────────────────────────────────────
function PageHeader({ title, subtitle, action, secondaryAction }) {
  return (
    <div
      style={{
        display: "flex",
        justifyContent: "space-between",
        alignItems: "flex-start",
        gap: 16,
        marginBottom: 20,
        flexWrap: "wrap",
      }}
    >
      <div style={{ minWidth: 0 }}>
        <h1 style={{ fontSize: 22, fontWeight: 800, color: T.text, margin: 0 }}>
          {title}
        </h1>
        {subtitle && (
          <p
            style={{
              color: T.textMuted,
              fontSize: 13,
              margin: "6px 0 0",
              lineHeight: 1.6,
            }}
          >
            {subtitle}
          </p>
        )}
      </div>
      {(action || secondaryAction) && (
        <div
          style={{
            display: "flex",
            gap: 10,
            flexWrap: "wrap",
            justifyContent: "flex-end",
          }}
        >
          {secondaryAction}
          {action}
        </div>
      )}
    </div>
  );
}

// ─── Stat Card ────────────────────────────────────────────────────────────────
function StatCard({ label, value, icon, color, bg, delta, desc, featured }) {
  return (
    <div
      style={{
        background: featured ? T.primaryMid : T.card,
        borderRadius: 14,
        padding: "16px 18px",
        border: `1px solid ${featured ? T.primaryMid : T.border}`,
        position: "relative",
        overflow: "hidden",
      }}
    >
      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          marginBottom: 8,
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <div
            style={{
              width: 28,
              height: 28,
              borderRadius: 8,
              background: featured ? "rgba(255,255,255,0.2)" : bg,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
            }}
          >
            <Icon name={icon} size={14} color={featured ? "#fff" : color} />
          </div>
          <span
            style={{
              fontSize: 12,
              color: featured ? "rgba(255,255,255,0.85)" : T.textMuted,
              fontWeight: 500,
            }}
          >
            {label}
          </span>
        </div>
      </div>
      <div
        style={{
          fontSize: 28,
          fontWeight: 800,
          color: featured ? "#fff" : color,
          marginBottom: 3,
        }}
      >
        {value}
      </div>
      <div
        style={{
          fontSize: 11,
          color: featured ? "rgba(255,255,255,0.7)" : T.primary,
          fontWeight: 600,
          marginBottom: 2,
        }}
      >
        ↑ {delta}
      </div>
      <div
        style={{
          fontSize: 10,
          color: featured ? "rgba(255,255,255,0.55)" : T.textMuted,
        }}
      >
        {desc}
      </div>
      <svg
        style={{
          position: "absolute",
          bottom: 0,
          right: 0,
          opacity: featured ? 0.18 : 0.06,
        }}
        width={80}
        height={40}
        viewBox="0 0 80 40"
      >
        <polyline
          points="0,35 15,25 30,30 45,15 60,20 80,5"
          fill="none"
          stroke={featured ? "#fff" : color}
          strokeWidth={2}
        />
      </svg>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOGIN PAGE
// ═══════════════════════════════════════════════════════════════════════════════
function LoginPage({ onLogin, onKiosk }) {
  const [form, setForm] = useState({
    email: "admin@sukat.ph",
    password: "admin123",
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const CREDS = {
    "admin@sukat.ph": {
      password: "admin123",
      role: "admin",
      name: "Super Admin",
    },
    "nutritionist@sukat.ph": {
      password: "health123",
      role: "nutritionist",
      name: "Dr. Maria Santos",
    },
    "cynthia@sukat.ph": {
      password: "health123",
      role: "nutritionist",
      name: "Nurse Cynthia Reyes",
    },
  };

  const handleLogin = () => {
    setLoading(true);
    setError("");
    setTimeout(() => {
      const cred = CREDS[form.email];
      if (cred && cred.password === form.password) {
        onLogin({ name: cred.name, role: cred.role, email: form.email });
      } else {
        setError("Invalid email or password. Check demo credentials below.");
        setLoading(false);
      }
    }, 1000);
  };

  // Role icons as inline SVGs
  const RoleIcon = ({ type }) => {
    const icons = {
      admin: { path: PATHS.settings, color: "#A78BFA" },
      nutritionist: { path: PATHS.hospital, color: "#6EE7B7" },
      kiosk: { path: PATHS.kiosk, color: "#93C5FD" },
    };
    const ic = icons[type];
    return (
      <svg
        width={18}
        height={18}
        viewBox="0 0 24 24"
        fill="none"
        stroke={ic.color}
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d={ic.path} />
      </svg>
    );
  };

  return (
    <div
      style={{
        minHeight: "100vh",
        background:
          "radial-gradient(circle at top left, rgba(43,200,138,0.16), transparent 28%), radial-gradient(circle at top right, rgba(245,166,35,0.12), transparent 24%), linear-gradient(135deg,#0D2B20 0%,#0B4A34 100%)",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        fontFamily: APP_FONT,
        padding: 24,
      }}
    >
      <div
        style={{
          display: "flex",
          width: "100%",
          maxWidth: 940,
          minHeight: 540,
          borderRadius: 28,
          overflow: "hidden",
          boxShadow: "0 36px 90px rgba(0,0,0,0.34)",
          border: "1px solid rgba(255,255,255,0.12)",
          backdropFilter: "blur(18px)",
          background: "rgba(255,255,255,0.06)",
        }}
      >
        {/* Left panel */}
        <div
          style={{
            flex: 1,
            background:
              "radial-gradient(circle at top right, rgba(255,255,255,0.08), transparent 32%), linear-gradient(160deg,#0B6E4F 0%,#0D4A32 100%)",
            padding: 48,
            display: "flex",
            flexDirection: "column",
            justifyContent: "space-between",
            position: "relative",
            overflow: "hidden",
          }}
        >
          <div style={{ minHeight: 0, flex: 1 }}>
            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 12,
                marginBottom: 40,
              }}
            >
              <div
                style={{
                  width: 44,
                  height: 44,
                  borderRadius: 12,
                  background: "rgba(255,255,255,0.15)",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                }}
              >
                <Icon name="heart" size={22} color="#fff" />
              </div>
              <div>
                <div
                  style={{
                    color: "#fff",
                    fontWeight: 800,
                    fontSize: 18,
                    letterSpacing: 0.5,
                  }}
                >
                  SukatKalusugan
                </div>
                <div
                  style={{
                    color: "rgba(255,255,255,0.5)",
                    fontSize: 10,
                    letterSpacing: 2,
                  }}
                >
                  HEALTH MONITORING SYSTEM
                </div>
              </div>
            </div>
            <h2
              style={{
                color: "#fff",
                fontSize: 28,
                fontWeight: 800,
                lineHeight: 1.3,
                margin: "0 0 12px",
                letterSpacing: -0.4,
              }}
            >
              Monitoring Child Growth with WHO Standards
            </h2>
            <p
              style={{
                color: "rgba(255,255,255,0.6)",
                fontSize: 13,
                lineHeight: 1.7,
              }}
            >
              A kiosk-based anthropometric system for children aged 0–59 months
              using eOPT+ framework and WHO growth standards.
            </p>
            <div style={{ marginTop: 32, display: "grid", gap: 10 }}>
              {[
                [
                  "Admin Panel",
                  "System management, sensor calibration, audit logs",
                  "admin",
                ],
                [
                  "Nutritionist Panel",
                  "WHO monitoring, growth analysis, appointments",
                  "nutritionist",
                ],
                [
                  "Kiosk Interface",
                  "IoT measurement station for direct child assessment",
                  "kiosk",
                ],
              ].map(([t, d, type]) => (
                <div
                  key={t}
                  style={{
                    display: "flex",
                    alignItems: "flex-start",
                    gap: 10,
                    background: "rgba(255,255,255,0.07)",
                    borderRadius: 10,
                    padding: "10px 14px",
                  }}
                >
                  <div style={{ marginTop: 1, flexShrink: 0 }}>
                    <RoleIcon type={type} />
                  </div>
                  <div>
                    <div
                      style={{ color: "#fff", fontWeight: 700, fontSize: 12 }}
                    >
                      {t}
                    </div>
                    <div
                      style={{ color: "rgba(255,255,255,0.5)", fontSize: 11 }}
                    >
                      {d}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <button
            onClick={onKiosk}
            style={{
              background: "rgba(255,255,255,0.14)",
              color: "#fff",
              border: "1px solid rgba(255,255,255,0.2)",
              borderRadius: 999,
              padding: "13px 24px",
              fontSize: 13,
              fontWeight: 700,
              cursor: "pointer",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              gap: 10,
              marginTop: "auto",
              alignSelf: "stretch",
              boxShadow: "0 10px 24px rgba(0,0,0,0.12)",
            }}
          >
            <Icon name="kiosk" size={16} color="#fff" /> Open Kiosk Mode
          </button>
        </div>

        {/* Right panel */}
        <div
          style={{
            width: 400,
            background:
              "linear-gradient(180deg, rgba(255,255,255,0.96) 0%, rgba(248,250,249,0.98) 100%)",
            padding: 48,
            display: "flex",
            flexDirection: "column",
            justifyContent: "center",
            position: "relative",
          }}
        >
          <h2
            style={{
              fontSize: 24,
              fontWeight: 800,
              color: T.text,
              margin: "0 0 4px",
              letterSpacing: -0.3,
            }}
          >
            Welcome back
          </h2>
          <p style={{ color: T.textMuted, fontSize: 13, margin: "0 0 32px" }}>
            Sign in to access your panel
          </p>
          <div style={{ marginBottom: 16 }}>
            <label
              style={{
                display: "block",
                fontSize: 12,
                fontWeight: 600,
                color: T.textMuted,
                marginBottom: 6,
              }}
            >
              EMAIL ADDRESS
            </label>
            <input
              type="email"
              value={form.email}
              onChange={(e) =>
                setForm((p) => ({ ...p, email: e.target.value }))
              }
              style={inputS}
            />
          </div>
          <div style={{ marginBottom: 20 }}>
            <label
              style={{
                display: "block",
                fontSize: 12,
                fontWeight: 600,
                color: T.textMuted,
                marginBottom: 6,
              }}
            >
              PASSWORD
            </label>
            <input
              type="password"
              value={form.password}
              onChange={(e) =>
                setForm((p) => ({ ...p, password: e.target.value }))
              }
              onKeyDown={(e) => e.key === "Enter" && handleLogin()}
              style={inputS}
            />
          </div>
          {error && (
            <div
              style={{
                background: T.dangerLight,
                color: T.danger,
                fontSize: 12,
                padding: "8px 12px",
                borderRadius: 8,
                marginBottom: 16,
              }}
            >
              {error}
            </div>
          )}
          <button
            onClick={handleLogin}
            style={{
              width: "100%",
              background: loading
                ? T.primaryLight
                : "linear-gradient(135deg, #0B6E4F 0%, #1A8F68 100%)",
              color: loading ? T.primary : "#fff",
              border: "none",
              borderRadius: 14,
              padding: "14px 0",
              fontSize: 14,
              fontWeight: 700,
              cursor: "pointer",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              gap: 8,
              boxShadow: "0 14px 24px rgba(11,110,79,0.18)",
            }}
          >
            {loading ? "Signing in..." : "Sign In"}
            {!loading && <Icon name="arrowRight" size={14} color="#fff" />}
          </button>
          <div
            style={{
              marginTop: 20,
              padding: 14,
              background: T.bg,
              borderRadius: 10,
              fontSize: 11,
              color: T.textMuted,
              lineHeight: 1.7,
            }}
          >
            <strong>Demo credentials</strong>
            <br />
            <span style={{ display: "flex", alignItems: "center", gap: 5 }}>
              <Icon name="lock" size={11} color={T.purple} /> Admin:
              admin@sukat.ph / admin123
            </span>
            <span style={{ display: "flex", alignItems: "center", gap: 5 }}>
              <Icon name="hospital" size={11} color={T.primaryMid} />{" "}
              Nutritionist: nutritionist@sukat.ph / health123
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
// APP SHELL (Sidebar + Top Bar)
// ═══════════════════════════════════════════════════════════════════════════════
function AppShell({
  user,
  navItems,
  page,
  onNav,
  onLogout,
  children,
  alerts = 0,
}) {
  return (
    <div
      style={{
        display: "flex",
        minHeight: "100vh",
        fontFamily: APP_FONT,
        background:
          "linear-gradient(180deg, #F7FAF8 0%, #EEF4F1 40%, #F5F7F6 100%)",
      }}
    >
      {/* Sidebar */}
      <div
        style={{
          width: 224,
          background:
            "linear-gradient(180deg, #0D2B20 0%, #102F24 52%, #0B241B 100%)",
          display: "flex",
          flexDirection: "column",
          flexShrink: 0,
          position: "sticky",
          top: 0,
          height: "100vh",
          boxShadow: "8px 0 30px rgba(13,43,32,0.12)",
        }}
      >
        <div
          style={{
            padding: "18px 16px",
            borderBottom: "1px solid rgba(255,255,255,0.07)",
          }}
        >
          <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
            <div
              style={{
                width: 32,
                height: 32,
                borderRadius: 8,
                background: T.primaryMid,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
              }}
            >
              <Icon name="heart" size={16} color="#fff" />
            </div>
            <div>
              <div
                style={{
                  color: "#fff",
                  fontWeight: 700,
                  fontSize: 13,
                  lineHeight: 1.2,
                }}
              >
                SukatKalusugan
              </div>
              <div
                style={{
                  color: "rgba(255,255,255,0.4)",
                  fontSize: 9,
                  letterSpacing: 1,
                  textTransform: "uppercase",
                }}
              >
                {user.role} panel
              </div>
            </div>
          </div>
        </div>
        <nav style={{ flex: 1, padding: "8px 10px 4px", overflowY: "auto" }}>
          {navItems.map((section, si) => (
            <div key={si}>
              {section.label && (
                <div
                  style={{
                    fontSize: 9,
                    color: "rgba(255,255,255,0.25)",
                    letterSpacing: 1.5,
                    padding: "10px 8px 6px",
                    fontWeight: 600,
                  }}
                >
                  {section.label}
                </div>
              )}
              {section.items.map((n) => (
                <button
                  key={n.id}
                  onClick={() => onNav(n.id)}
                  style={{
                    width: "100%",
                    display: "flex",
                    alignItems: "center",
                    gap: 9,
                    padding: "9px 12px",
                    borderRadius: 9,
                    border: "none",
                    cursor: "pointer",
                    marginBottom: 2,
                    textAlign: "left",
                    background:
                      page === n.id
                        ? "linear-gradient(135deg, rgba(26,143,104,0.28) 0%, rgba(43,200,138,0.16) 100%)"
                        : "transparent",
                    color: page === n.id ? "#2BC88A" : "rgba(255,255,255,0.55)",
                    borderLeft:
                      page === n.id
                        ? "2px solid #2BC88A"
                        : "2px solid transparent",
                  }}
                >
                  <Icon
                    name={n.icon}
                    size={15}
                    color={page === n.id ? "#2BC88A" : "rgba(255,255,255,0.55)"}
                  />
                  <span
                    style={{
                      fontSize: 12,
                      fontWeight: page === n.id ? 600 : 400,
                    }}
                  >
                    {n.label}
                  </span>
                  {n.badge && (
                    <span
                      style={{
                        marginLeft: "auto",
                        fontSize: 8,
                        background: T.danger,
                        color: "#fff",
                        padding: "1px 6px",
                        borderRadius: 4,
                      }}
                    >
                      {n.badge}
                    </span>
                  )}
                </button>
              ))}
            </div>
          ))}
        </nav>
        <div
          style={{
            padding: "12px 14px",
            borderTop: "1px solid rgba(255,255,255,0.07)",
          }}
        >
          <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
            <div
              style={{
                width: 30,
                height: 30,
                borderRadius: "50%",
                background: T.primaryMid,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                fontSize: 11,
                fontWeight: 700,
                color: "#fff",
              }}
            >
              {user.name
                .split(" ")
                .map((n) => n[0])
                .join("")
                .slice(0, 2)}
            </div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div
                style={{
                  color: "#fff",
                  fontSize: 12,
                  fontWeight: 600,
                  overflow: "hidden",
                  textOverflow: "ellipsis",
                  whiteSpace: "nowrap",
                }}
              >
                {user.name}
              </div>
              <div
                style={{
                  color: "rgba(255,255,255,0.35)",
                  fontSize: 10,
                  textTransform: "capitalize",
                }}
              >
                {user.role}
              </div>
            </div>
            <button
              onClick={onLogout}
              style={{
                background: "none",
                border: "none",
                color: "rgba(255,255,255,0.3)",
                cursor: "pointer",
                padding: 4,
              }}
            >
              <Icon name="logout" size={13} color="rgba(255,255,255,0.5)" />
            </button>
          </div>
        </div>
      </div>

      {/* Main */}
      <div
        style={{
          flex: 1,
          display: "flex",
          flexDirection: "column",
          minWidth: 0,
        }}
      >
        <div
          style={{
            background: T.card,
            borderBottom: `1px solid ${T.border}`,
            padding: "12px 24px",
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
            position: "sticky",
            top: 0,
            zIndex: 10,
          }}
        >
          <div>
            <div style={{ fontSize: 13, fontWeight: 700, color: T.text }}>
              Good morning, {user.name.split(" ").slice(-1)[0]}!
            </div>
            <div style={{ fontSize: 10, color: T.textMuted, marginTop: 2 }}>
              SukatKalusugan ·{" "}
              {new Date().toLocaleDateString("en-PH", {
                weekday: "long",
                year: "numeric",
                month: "long",
                day: "numeric",
              })}
            </div>
          </div>
          <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
            <div style={{ position: "relative", cursor: "pointer" }}>
              <Icon name="bell" size={18} color={T.textMuted} />
              {alerts > 0 && (
                <span
                  style={{
                    position: "absolute",
                    top: -2,
                    right: -4,
                    background: T.danger,
                    color: "#fff",
                    borderRadius: "50%",
                    width: 14,
                    height: 14,
                    fontSize: 9,
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    fontWeight: 700,
                  }}
                >
                  {alerts}
                </span>
              )}
            </div>
            <div
              style={{
                width: 32,
                height: 32,
                borderRadius: "50%",
                background: T.primaryMid,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                color: "#fff",
                fontSize: 13,
                fontWeight: 700,
                cursor: "pointer",
              }}
            >
              {user.name
                .split(" ")
                .map((n) => n[0])
                .join("")
                .slice(0, 2)}
            </div>
            <button
              onClick={onLogout}
              style={{
                background: T.dangerLight,
                color: T.danger,
                border: "none",
                borderRadius: 8,
                padding: "6px 12px",
                fontSize: 12,
                fontWeight: 600,
                cursor: "pointer",
              }}
            >
              Sign out
            </button>
          </div>
        </div>
        <div style={{ flex: 1, padding: 24, overflowY: "auto" }}>
          {children}
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN PAGES
// ═══════════════════════════════════════════════════════════════════════════════

function AdminDashboard({ users, auditLogs }) {
  const adminCount = users.filter((u) => u.role === "admin").length;
  const nutritionistCount = users.filter(
    (u) => u.role === "nutritionist",
  ).length;
  const activeCount = users.filter((u) => u.status === "Active").length;
  const errorLogs = auditLogs.filter((l) => l.level === "danger").length;

  const cards = [
    {
      label: "Total Users",
      value: users.length,
      icon: "users",
      color: T.primary,
      bg: T.primaryLight,
      delta: `${activeCount} active`,
      desc: "System accounts",
      featured: true,
    },
    {
      label: "Admins",
      value: adminCount,
      icon: "settings",
      color: T.purple,
      bg: T.purpleLight,
      delta: "All active",
      desc: "Administrator accounts",
    },
    {
      label: "Nutritionists",
      value: nutritionistCount,
      icon: "activity",
      color: T.info,
      bg: T.infoLight,
      delta: "Field workers",
      desc: "Health professionals",
    },
    {
      label: "Error Logs",
      value: errorLogs,
      icon: "alertTriangle",
      color: T.danger,
      bg: T.dangerLight,
      delta: "Needs review",
      desc: "System errors today",
    },
  ];

  const KIOSKS = [
    {
      id: "KIOSK-01",
      location: "Bagong Silang BHC",
      status: "Online",
      last_sync: "5 min ago",
      measurements_today: 12,
    },
    {
      id: "KIOSK-02",
      location: "Poblacion BHC",
      status: "Online",
      last_sync: "2 min ago",
      measurements_today: 8,
    },
    {
      id: "KIOSK-03",
      location: "San Jose BHC",
      status: "Offline",
      last_sync: "3 hr ago",
      measurements_today: 0,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Admin Dashboard"
        subtitle="System overview and operational status"
      />
      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(4,1fr)",
          gap: 12,
          marginBottom: 20,
        }}
      >
        {cards.map((c, i) => (
          <StatCard key={c.label} {...c} featured={i === 0} />
        ))}
      </div>
      <div
        style={{
          display: "grid",
          gridTemplateColumns: "1fr 1fr",
          gap: 16,
          marginBottom: 20,
        }}
      >
        {/* Kiosk Status */}
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 20,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 4,
            }}
          >
            Kiosk Status
          </div>
          <div style={{ fontSize: 12, color: T.textMuted, marginBottom: 16 }}>
            Live kiosk monitoring
          </div>
          {KIOSKS.map((k) => (
            <div
              key={k.id}
              style={{
                display: "flex",
                alignItems: "center",
                justifyContent: "space-between",
                padding: "12px 0",
                borderBottom: `1px solid ${T.border}`,
              }}
            >
              <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                <div
                  style={{
                    width: 8,
                    height: 8,
                    borderRadius: "50%",
                    background: k.status === "Online" ? T.primaryMid : T.danger,
                  }}
                />
                <div>
                  <div style={{ fontSize: 13, fontWeight: 600, color: T.text }}>
                    {k.id}
                  </div>
                  <div style={{ fontSize: 11, color: T.textMuted }}>
                    {k.location}
                  </div>
                </div>
              </div>
              <div style={{ textAlign: "right" }}>
                <div
                  style={{
                    fontSize: 11,
                    color: k.status === "Online" ? T.primary : T.danger,
                    fontWeight: 600,
                  }}
                >
                  {k.status}
                </div>
                <div style={{ fontSize: 10, color: T.textMuted }}>
                  Sync: {k.last_sync} · {k.measurements_today} today
                </div>
              </div>
            </div>
          ))}
        </div>
        {/* Recent Audit */}
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 20,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 4,
            }}
          >
            Recent Activity
          </div>
          <div style={{ fontSize: 12, color: T.textMuted, marginBottom: 16 }}>
            Latest audit events
          </div>
          {auditLogs.slice(0, 5).map((log) => (
            <div
              key={log.id}
              style={{
                display: "flex",
                alignItems: "flex-start",
                gap: 10,
                padding: "8px 0",
                borderBottom: `1px solid ${T.border}`,
              }}
            >
              <div
                style={{
                  width: 6,
                  height: 6,
                  borderRadius: "50%",
                  background:
                    log.level === "danger"
                      ? T.danger
                      : log.level === "warn"
                        ? T.warn
                        : T.primaryMid,
                  marginTop: 5,
                  flexShrink: 0,
                }}
              />
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 11, fontWeight: 600, color: T.text }}>
                  {log.action}
                </div>
                <div
                  style={{
                    fontSize: 10,
                    color: T.textMuted,
                    overflow: "hidden",
                    textOverflow: "ellipsis",
                    whiteSpace: "nowrap",
                  }}
                >
                  {log.detail}
                </div>
                <div style={{ fontSize: 9, color: T.textLight }}>
                  {log.timestamp}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
      {/* System Health */}
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          border: `1px solid ${T.border}`,
          padding: 20,
        }}
      >
        <div
          style={{
            fontWeight: 700,
            fontSize: 15,
            color: T.text,
            marginBottom: 16,
          }}
        >
          System Health
        </div>
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(4,1fr)",
            gap: 12,
          }}
        >
          {[
            {
              label: "Database",
              value: "Healthy",
              icon: "cpu",
              color: T.primary,
            },
            {
              label: "eOPT+ Sync",
              value: "Connected",
              icon: "wifi",
              color: T.primaryMid,
            },
            {
              label: "API Server",
              value: "Running",
              icon: "zap",
              color: T.accent,
            },
            {
              label: "Last Backup",
              value: "2h ago",
              icon: "save",
              color: T.info,
            },
          ].map((item) => (
            <div
              key={item.label}
              style={{
                background: T.bg,
                borderRadius: 12,
                padding: 14,
                display: "flex",
                alignItems: "center",
                gap: 10,
              }}
            >
              <div
                style={{
                  width: 36,
                  height: 36,
                  borderRadius: 10,
                  background: item.color + "18",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                }}
              >
                <Icon name={item.icon} size={16} color={item.color} />
              </div>
              <div>
                <div style={{ fontSize: 11, color: T.textMuted }}>
                  {item.label}
                </div>
                <div
                  style={{ fontSize: 13, fontWeight: 700, color: T.primary }}
                >
                  {item.value}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function UserManagement({ users, onAdd, onEdit, onDelete, showToast }) {
  const [modal, setModal] = useState(null);
  const [viewUser, setViewUser] = useState(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("All Status");
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 10;

  const [form, setForm] = useState({
    name: "",
    email: "",
    username: "",
    phone: "",
    role: "nutritionist",
    barangay: "",
    status: "Active",
    date_created: new Date().toISOString().split("T")[0],
  });
  const [confirm, setConfirm] = useState(null);

  const filteredUsers = users.filter((u) => {
    const matchSearch =
      u.name.toLowerCase().includes(search.toLowerCase()) ||
      u.email.toLowerCase().includes(search.toLowerCase()) ||
      (u.username && u.username.toLowerCase().includes(search.toLowerCase()));
    const matchStatus =
      statusFilter === "All Status" || u.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const totalPages = Math.ceil(filteredUsers.length / itemsPerPage);
  const paginatedUsers = filteredUsers.slice(
    (currentPage - 1) * itemsPerPage,
    currentPage * itemsPerPage,
  );

  const openAdd = () => {
    setForm({
      name: "",
      email: "",
      username: "",
      phone: "",
      role: "nutritionist",
      barangay: "",
      status: "Active",
      date_created: new Date().toISOString().split("T")[0],
    });
    setModal("add");
  };
  const openEdit = (u) => {
    setForm({ ...u });
    setModal("edit");
  };
  const handleSave = () => {
    if (!form.name || !form.email || !form.username) return;
    if (modal === "add") onAdd(form);
    else onEdit(form);
    setModal(null);
    showToast(modal === "add" ? "User added" : "User updated");
  };

  const roleColor = { admin: T.purple, nutritionist: T.info };
  const roleBg = { admin: T.purpleLight, nutritionist: T.infoLight };

  return (
    <div>
      <PageHeader
        title="User Management"
        subtitle={`${filteredUsers.length} of ${users.length} system accounts`}
        action={
          <button onClick={openAdd} style={btnPrimary}>
            <Icon name="plus" size={14} color="#fff" />
            Add User
          </button>
        }
      />

      {/* Search and Filters */}
      <div
        style={{
          background: T.card,
          borderRadius: 12,
          border: `1px solid ${T.border}`,
          padding: 16,
          marginBottom: 20,
          display: "flex",
          gap: 12,
          alignItems: "center",
        }}
      >
        <input
          type="text"
          placeholder="Search Username"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setCurrentPage(1);
          }}
          style={{
            flex: 1,
            background: T.bg,
            border: `1px solid ${T.border}`,
            borderRadius: 8,
            padding: "8px 12px",
            fontSize: 13,
            outline: "none",
          }}
        />
        <select
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setCurrentPage(1);
          }}
          style={{
            background: T.bg,
            border: `1px solid ${T.border}`,
            borderRadius: 8,
            padding: "8px 12px",
            fontSize: 13,
            outline: "none",
            cursor: "pointer",
            minWidth: 120,
          }}
        >
          <option>All Status</option>
          <option>Active</option>
          <option>Inactive</option>
        </select>
        <button
          onClick={() => {
            const csv = [
              "Role,Full Name,Username,Email,Phone Number,Date Created,Status",
            ];
            filteredUsers.forEach((u) => {
              csv.push(
                `${u.role},${u.name},${u.username || ""},${u.email},${u.phone || ""},${u.date_created || ""},${u.status}`,
              );
            });
            const link = document.createElement("a");
            link.href = URL.createObjectURL(
              new Blob([csv.join("\n")], { type: "text/csv" }),
            );
            link.download = "users.csv";
            link.click();
            showToast("Users exported");
          }}
          style={{
            background: T.bg,
            border: `1px solid ${T.border}`,
            borderRadius: 8,
            padding: "8px 16px",
            fontSize: 13,
            cursor: "pointer",
            display: "flex",
            alignItems: "center",
            gap: 6,
            color: T.text,
            fontWeight: 600,
          }}
        >
          <Icon name="download" size={14} color={T.text} />
          Export
        </button>
      </div>
      {modal && (
        <Modal
          title={modal === "add" ? "Add User" : "Edit User"}
          onClose={() => setModal(null)}
          width={520}
        >
          <div style={{ padding: 24 }}>
            <Field label="Full Name" required>
              <input
                style={inputS}
                value={form.name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, name: e.target.value }))
                }
                placeholder="e.g. John Doe"
              />
            </Field>
            <Field label="Username" required>
              <input
                style={inputS}
                value={form.username || ""}
                onChange={(e) =>
                  setForm((p) => ({ ...p, username: e.target.value }))
                }
                placeholder="e.g. johndoe12"
              />
            </Field>
            <Field label="Email Address" required>
              <input
                type="email"
                style={inputS}
                value={form.email}
                onChange={(e) =>
                  setForm((p) => ({ ...p, email: e.target.value }))
                }
                placeholder="e.g. john@example.com"
              />
            </Field>
            <Field label="Phone Number">
              <input
                style={inputS}
                value={form.phone || ""}
                onChange={(e) =>
                  setForm((p) => ({ ...p, phone: e.target.value }))
                }
                placeholder="e.g. 0813-2222-8899"
              />
            </Field>
            <Field label="Role">
              <select
                style={selectS}
                value={form.role}
                onChange={(e) =>
                  setForm((p) => ({ ...p, role: e.target.value }))
                }
              >
                <option value="admin">Super Admin</option>
                <option value="nutritionist">Nutritionist</option>
              </select>
            </Field>
            <Field label="Assigned Barangay">
              <input
                style={inputS}
                value={form.barangay || ""}
                onChange={(e) =>
                  setForm((p) => ({ ...p, barangay: e.target.value }))
                }
                placeholder="e.g. Bagong Silang or All"
              />
            </Field>
            <Field label="Status">
              <select
                style={selectS}
                value={form.status}
                onChange={(e) =>
                  setForm((p) => ({ ...p, status: e.target.value }))
                }
              >
                <option>Active</option>
                <option>Inactive</option>
              </select>
            </Field>
            <div
              style={{
                display: "flex",
                justifyContent: "flex-end",
                gap: 10,
                marginTop: 6,
              }}
            >
              <button onClick={() => setModal(null)} style={btnSecondary}>
                Cancel
              </button>
              <button
                onClick={handleSave}
                style={{ ...btnPrimary, padding: "9px 20px" }}
              >
                {modal === "add" ? "Add User" : "Save Changes"}
              </button>
            </div>
          </div>
        </Modal>
      )}
      {confirm && (
        <ConfirmDialog
          msg={`Delete user ${confirm.name}? This cannot be undone.`}
          onConfirm={() => {
            onDelete(confirm);
            setConfirm(null);
            showToast(`${confirm.name} deleted`, "danger");
          }}
          onCancel={() => setConfirm(null)}
        />
      )}

      {viewUser && (
        <Modal
          title="User Details"
          onClose={() => setViewUser(null)}
          width={480}
        >
          <div style={{ padding: 24 }}>
            <div style={{ marginBottom: 20 }}>
              <div
                style={{
                  width: 64,
                  height: 64,
                  borderRadius: 12,
                  background:
                    viewUser.role === "admin" ? T.purpleLight : T.infoLight,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  fontSize: 24,
                  fontWeight: 700,
                  color: viewUser.role === "admin" ? T.purple : T.info,
                  marginBottom: 12,
                }}
              >
                {viewUser.name
                  .split(" ")
                  .map((n) => n[0])
                  .join("")
                  .slice(0, 2)}
              </div>
              <h3 style={{ margin: "0 0 4px", color: T.text }}>
                {viewUser.name}
              </h3>
              <p style={{ margin: 0, fontSize: 12, color: T.textMuted }}>
                {viewUser.email}
              </p>
            </div>

            <div
              style={{
                display: "grid",
                gridTemplateColumns: "1fr 1fr",
                gap: 16,
                marginBottom: 20,
              }}
            >
              {[
                ["Username", viewUser.username || "—"],
                ["Role", viewUser.role],
                ["Status", viewUser.status],
                ["Phone", viewUser.phone || "—"],
                ["Barangay", viewUser.barangay || "All"],
                ["Date Created", viewUser.date_created || "—"],
              ].map(([label, value]) => (
                <div key={label}>
                  <div
                    style={{
                      fontSize: 11,
                      color: T.textMuted,
                      marginBottom: 4,
                    }}
                  >
                    {label}
                  </div>
                  <div
                    style={{
                      fontSize: 13,
                      fontWeight: 600,
                      color: T.text,
                    }}
                  >
                    {value}
                  </div>
                </div>
              ))}
            </div>

            <div
              style={{ display: "flex", justifyContent: "flex-end", gap: 10 }}
            >
              <button
                onClick={() => {
                  setViewUser(null);
                  openEdit(viewUser);
                }}
                style={{
                  ...btnPrimary,
                  padding: "9px 20px",
                }}
              >
                Edit User
              </button>
              <button
                onClick={() => setViewUser(null)}
                style={{
                  ...btnSecondary,
                }}
              >
                Close
              </button>
            </div>
          </div>
        </Modal>
      )}
      <div
        style={{
          background: T.card,
          borderRadius: 12,
          border: `1px solid ${T.border}`,
          overflow: "hidden",
        }}
      >
        <table style={{ width: "100%", borderCollapse: "collapse" }}>
          <thead>
            <tr style={{ background: T.bg }}>
              {[
                "Role",
                "Full Name",
                "Username",
                "Email",
                "Phone Number",
                "Date Created",
                "Status",
                "Actions",
              ].map((h) => (
                <th
                  key={h}
                  style={{
                    textAlign: "left",
                    padding: "12px 16px",
                    fontSize: 11,
                    color: T.textMuted,
                    fontWeight: 700,
                    letterSpacing: 0.5,
                    borderBottom: `1px solid ${T.border}`,
                  }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {paginatedUsers.map((u, i) => (
              <tr
                key={u.id}
                style={{
                  borderBottom:
                    i < paginatedUsers.length - 1
                      ? `1px solid ${T.border}`
                      : "none",
                }}
              >
                <td style={{ padding: "12px 16px" }}>
                  <span
                    style={{
                      background: roleBg[u.role],
                      color: roleColor[u.role],
                      fontSize: 11,
                      fontWeight: 700,
                      padding: "4px 10px",
                      borderRadius: 6,
                      textTransform: "capitalize",
                    }}
                  >
                    {u.role}
                  </span>
                </td>
                <td
                  style={{
                    padding: "12px 16px",
                    fontSize: 13,
                    fontWeight: 600,
                    color: T.text,
                  }}
                >
                  {u.name}
                </td>
                <td
                  style={{
                    padding: "12px 16px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {u.username || "—"}
                </td>
                <td
                  style={{
                    padding: "12px 16px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {u.email}
                </td>
                <td
                  style={{
                    padding: "12px 16px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {u.phone || "—"}
                </td>
                <td
                  style={{
                    padding: "12px 16px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {u.date_created || "—"}
                </td>
                <td style={{ padding: "12px 16px" }}>
                  <div
                    style={{ display: "flex", alignItems: "center", gap: 6 }}
                  >
                    <span
                      style={{
                        display: "inline-block",
                        width: 8,
                        height: 8,
                        borderRadius: "50%",
                        background:
                          u.status === "Active" ? T.primary : "#CBD5E1",
                      }}
                    />
                    <span
                      style={{
                        fontSize: 12,
                        fontWeight: 600,
                        color: u.status === "Active" ? T.primary : T.textMuted,
                      }}
                    >
                      {u.status}
                    </span>
                  </div>
                </td>
                <td style={{ padding: "12px 16px" }}>
                  <div style={{ display: "flex", gap: 8 }}>
                    <button
                      onClick={() => setViewUser(u)}
                      style={{
                        background: "transparent",
                        color: T.info,
                        border: "none",
                        cursor: "pointer",
                        padding: "4px 6px",
                      }}
                      title="View"
                    >
                      <Icon name="eye" size={14} color={T.info} />
                    </button>
                    <button
                      onClick={() => openEdit(u)}
                      style={{
                        background: "transparent",
                        color: T.accent,
                        border: "none",
                        cursor: "pointer",
                        padding: "4px 6px",
                      }}
                      title="Edit"
                    >
                      <Icon name="edit" size={14} color={T.accent} />
                    </button>
                    <button
                      onClick={() => setConfirm(u)}
                      style={{
                        background: "transparent",
                        color: T.danger,
                        border: "none",
                        cursor: "pointer",
                        padding: "4px 6px",
                      }}
                      title="Delete"
                    >
                      <Icon name="trash" size={14} color={T.danger} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div
          style={{
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
            padding: "16px 0",
            fontSize: 12,
            color: T.textMuted,
          }}
        >
          <div>
            Showing {(currentPage - 1) * itemsPerPage + 1} to{" "}
            {Math.min(currentPage * itemsPerPage, filteredUsers.length)} of{" "}
            {filteredUsers.length} results
          </div>
          <div style={{ display: "flex", gap: 4 }}>
            <button
              onClick={() => setCurrentPage(1)}
              disabled={currentPage === 1}
              style={{
                padding: "6px 10px",
                border: `1px solid ${T.border}`,
                borderRadius: 6,
                background: currentPage === 1 ? T.bg : T.card,
                cursor: currentPage === 1 ? "default" : "pointer",
                fontSize: 11,
              }}
            >
              First
            </button>
            <button
              onClick={() => setCurrentPage(Math.max(1, currentPage - 1))}
              disabled={currentPage === 1}
              style={{
                padding: "6px 10px",
                border: `1px solid ${T.border}`,
                borderRadius: 6,
                background: currentPage === 1 ? T.bg : T.card,
                cursor: currentPage === 1 ? "default" : "pointer",
                fontSize: 11,
              }}
            >
              Previous
            </button>
            {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
              const pageNum = i + 1;
              return (
                <button
                  key={pageNum}
                  onClick={() => setCurrentPage(pageNum)}
                  style={{
                    padding: "6px 10px",
                    border: `1px solid ${T.border}`,
                    borderRadius: 6,
                    background: currentPage === pageNum ? T.primary : T.card,
                    color: currentPage === pageNum ? "#fff" : T.text,
                    cursor: "pointer",
                    fontSize: 11,
                    fontWeight: currentPage === pageNum ? 700 : 600,
                  }}
                >
                  {pageNum}
                </button>
              );
            })}
            <button
              onClick={() =>
                setCurrentPage(Math.min(totalPages, currentPage + 1))
              }
              disabled={currentPage === totalPages}
              style={{
                padding: "6px 10px",
                border: `1px solid ${T.border}`,
                borderRadius: 6,
                background: currentPage === totalPages ? T.bg : T.card,
                cursor: currentPage === totalPages ? "default" : "pointer",
                fontSize: 11,
              }}
            >
              Next
            </button>
            <button
              onClick={() => setCurrentPage(totalPages)}
              disabled={currentPage === totalPages}
              style={{
                padding: "6px 10px",
                border: `1px solid ${T.border}`,
                borderRadius: 6,
                background: currentPage === totalPages ? T.bg : T.card,
                cursor: currentPage === totalPages ? "default" : "pointer",
                fontSize: 11,
              }}
            >
              Last
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function SensorCalibration({ showToast }) {
  const [hx711, setHx711] = useState({
    calibration_factor: "2280.5",
    known_weight: "500",
    tare_offset: "0",
    gain: "128",
    sample_rate: "10",
  });
  const [tfLuna, setTfLuna] = useState({
    baud_rate: "115200",
    sample_rate: "100",
    min_dist: "20",
    max_dist: "800",
    offset_cm: "0.0",
    mode: "short",
  });
  const [testResult, setTestResult] = useState(null);
  const [testing, setTesting] = useState(false);
  const [wifi, setWifi] = useState({
    ssid: "SukatBarangayNet",
    password: "",
    ipMode: "dhcp",
    staticIp: "192.168.1.80",
    gateway: "192.168.1.1",
    dns: "8.8.8.8",
    syncIntervalSec: "15",
  });
  const [wifiChecking, setWifiChecking] = useState(false);
  const [wifiStatus, setWifiStatus] = useState({
    state: "Connected",
    detail: "RSSI -54 dBm · Latency 18ms",
  });
  const [endpoint, setEndpoint] = useState({
    transport: "mqtt",
    brokerHost: "broker.hivemq.com",
    brokerPort: "1883",
    topicPublish: "sukat/esp32/measurements",
    topicSubscribe: "sukat/esp32/commands",
    apiBaseUrl: "https://eopt.doh.gov.ph/api",
    apiToken: "",
    deviceId: "ESP32-KIOSK-01",
    timeoutMs: "5000",
  });
  const [endpointTesting, setEndpointTesting] = useState("");
  const [endpointStatus, setEndpointStatus] = useState({
    mqtt: "Online",
    mqttDetail: "Broker reachable · Last publish 12s ago",
    api: "Online",
    apiDetail: "HTTP 200 · /health",
  });

  const runTest = (sensor) => {
    setTesting(sensor);
    setTestResult(null);
    setTimeout(() => {
      setTestResult({
        sensor,
        height:
          sensor === "tf_luna" ? (82.3 + Math.random() * 0.4).toFixed(1) : null,
        weight:
          sensor === "hx711" ? (10.2 + Math.random() * 0.1).toFixed(2) : null,
        status: "Pass",
      });
      setTesting(false);
    }, 2000);
  };

  const panelStyle = {
    background: T.card,
    borderRadius: 16,
    border: `1px solid ${T.border}`,
    padding: 24,
    marginBottom: 16,
  };

  return (
    <div>
      <PageHeader
        title="Sensor Calibration"
        subtitle="Configure and calibrate IoT sensors for accurate measurements"
      />
      <div style={panelStyle}>
        <SectionHead
          title="HX711 Load Cell Amplifier"
          desc="Weight measurement sensor calibration"
          icon="scale"
          color={T.warn}
        />
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(3,1fr)",
            gap: 14,
            marginBottom: 16,
          }}
        >
          {[
            ["Calibration Factor", "calibration_factor", "e.g. 2280.5"],
            ["Known Weight (g)", "known_weight", "e.g. 500"],
            ["Tare Offset", "tare_offset", "e.g. 0"],
            ["Gain (64/128)", "gain", "64 or 128"],
            ["Sample Rate (Hz)", "sample_rate", "e.g. 10"],
          ].map(([label, key, ph]) => (
            <Field key={key} label={label}>
              <input
                style={inputS}
                value={hx711[key]}
                onChange={(e) =>
                  setHx711((p) => ({ ...p, [key]: e.target.value }))
                }
                placeholder={ph}
              />
            </Field>
          ))}
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <button
            onClick={() => showToast("HX711 calibration saved", "success")}
            style={btnPrimary}
          >
            <Icon name="save" size={14} color="#fff" />
            Save Calibration
          </button>
          <button
            onClick={() => runTest("hx711")}
            style={{ ...btnPrimary, background: T.accent }}
          >
            <Icon name="activity" size={14} color="#fff" />
            {testing === "hx711" ? "Testing..." : "Run Test"}
          </button>
          {testResult?.sensor === "hx711" && (
            <span
              style={{
                background: T.primaryLight,
                color: T.primary,
                padding: "6px 14px",
                borderRadius: 8,
                fontSize: 12,
                fontWeight: 600,
              }}
            >
              <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
                <Icon name="check" size={12} color={T.accent} /> Test Weight:{" "}
                {testResult.weight}kg — {testResult.status}
              </span>
            </span>
          )}
        </div>
      </div>
      <div style={panelStyle}>
        <SectionHead
          title="TF-Luna LiDAR Sensor"
          desc="Height measurement sensor calibration"
          icon="ruler"
          color={T.info}
        />
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(3,1fr)",
            gap: 14,
            marginBottom: 16,
          }}
        >
          {[
            ["Baud Rate", "baud_rate", "e.g. 115200"],
            ["Sample Rate (Hz)", "sample_rate", "e.g. 100"],
            ["Min Distance (cm)", "min_dist", "e.g. 20"],
            ["Max Distance (cm)", "max_dist", "e.g. 800"],
            ["Offset (cm)", "offset_cm", "e.g. 0.0"],
            ["Mode", "mode", "short / long"],
          ].map(([label, key, ph]) => (
            <Field key={key} label={label}>
              {key === "mode" ? (
                <select
                  style={selectS}
                  value={tfLuna[key]}
                  onChange={(e) =>
                    setTfLuna((p) => ({ ...p, [key]: e.target.value }))
                  }
                >
                  <option value="short">Short Range (0–2m)</option>
                  <option value="long">Long Range (2–8m)</option>
                </select>
              ) : (
                <input
                  style={inputS}
                  value={tfLuna[key]}
                  onChange={(e) =>
                    setTfLuna((p) => ({ ...p, [key]: e.target.value }))
                  }
                  placeholder={ph}
                />
              )}
            </Field>
          ))}
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <button
            onClick={() => showToast("TF-Luna calibration saved", "success")}
            style={btnPrimary}
          >
            <Icon name="save" size={14} color="#fff" />
            Save Calibration
          </button>
          <button
            onClick={() => runTest("tf_luna")}
            style={{ ...btnPrimary, background: T.info }}
          >
            <Icon name="scan" size={14} color="#fff" />
            {testing === "tf_luna" ? "Scanning..." : "Run Test"}
          </button>
          {testResult?.sensor === "tf_luna" && (
            <span
              style={{
                background: T.infoLight,
                color: T.info,
                padding: "6px 14px",
                borderRadius: 8,
                fontSize: 12,
                fontWeight: 600,
              }}
            >
              <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
                <Icon name="check" size={12} color={T.accent} /> Measured
                Height: {testResult.height}cm — {testResult.status}
              </span>
            </span>
          )}
        </div>
      </div>

      <div style={panelStyle}>
        <SectionHead
          title="ESP32 Wi-Fi & Network"
          desc="Configure wireless connectivity for sensor streaming and sync"
          icon="wifi"
          color={T.primaryMid}
        />
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(3,1fr)",
            gap: 14,
            marginBottom: 16,
          }}
        >
          <Field label="Wi-Fi SSID">
            <input
              style={inputS}
              value={wifi.ssid}
              onChange={(e) => setWifi((p) => ({ ...p, ssid: e.target.value }))}
              placeholder="e.g. SukatBarangayNet"
            />
          </Field>
          <Field label="Wi-Fi Password">
            <input
              type="password"
              style={inputS}
              value={wifi.password}
              onChange={(e) =>
                setWifi((p) => ({ ...p, password: e.target.value }))
              }
              placeholder="Enter Wi-Fi password"
            />
          </Field>
          <Field label="IP Mode">
            <select
              style={selectS}
              value={wifi.ipMode}
              onChange={(e) =>
                setWifi((p) => ({ ...p, ipMode: e.target.value }))
              }
            >
              <option value="dhcp">DHCP (Automatic)</option>
              <option value="static">Static IP</option>
            </select>
          </Field>

          <Field label="Static IP">
            <input
              style={{
                ...inputS,
                opacity: wifi.ipMode === "static" ? 1 : 0.55,
              }}
              disabled={wifi.ipMode !== "static"}
              value={wifi.staticIp}
              onChange={(e) =>
                setWifi((p) => ({ ...p, staticIp: e.target.value }))
              }
              placeholder="e.g. 192.168.1.80"
            />
          </Field>
          <Field label="Gateway">
            <input
              style={{
                ...inputS,
                opacity: wifi.ipMode === "static" ? 1 : 0.55,
              }}
              disabled={wifi.ipMode !== "static"}
              value={wifi.gateway}
              onChange={(e) =>
                setWifi((p) => ({ ...p, gateway: e.target.value }))
              }
              placeholder="e.g. 192.168.1.1"
            />
          </Field>
          <Field label="DNS Server">
            <input
              style={{
                ...inputS,
                opacity: wifi.ipMode === "static" ? 1 : 0.55,
              }}
              disabled={wifi.ipMode !== "static"}
              value={wifi.dns}
              onChange={(e) => setWifi((p) => ({ ...p, dns: e.target.value }))}
              placeholder="e.g. 8.8.8.8"
            />
          </Field>

          <Field label="Telemetry Sync Interval (sec)">
            <input
              style={inputS}
              value={wifi.syncIntervalSec}
              onChange={(e) =>
                setWifi((p) => ({ ...p, syncIntervalSec: e.target.value }))
              }
              placeholder="e.g. 15"
            />
          </Field>
        </div>

        <div
          style={{
            display: "flex",
            alignItems: "center",
            gap: 10,
            flexWrap: "wrap",
          }}
        >
          <button
            onClick={() => {
              showToast("ESP32 Wi-Fi settings saved", "success");
            }}
            style={btnPrimary}
          >
            <Icon name="save" size={14} color="#fff" />
            Save Wi-Fi Settings
          </button>

          <button
            onClick={() => {
              setWifiChecking(true);
              setTimeout(() => {
                const ok = Math.random() > 0.15;
                setWifiStatus(
                  ok
                    ? {
                        state: "Connected",
                        detail: `SSID ${wifi.ssid || "(unset)"} · RSSI -${
                          45 + Math.floor(Math.random() * 20)
                        } dBm · Latency ${12 + Math.floor(Math.random() * 18)}ms`,
                      }
                    : {
                        state: "Disconnected",
                        detail:
                          "Unable to reach gateway. Check credentials or signal.",
                      },
                );
                setWifiChecking(false);
                showToast(
                  ok
                    ? "ESP32 Wi-Fi connected"
                    : "ESP32 Wi-Fi connection failed",
                  ok ? "success" : "danger",
                );
              }, 1300);
            }}
            style={{
              ...btnPrimary,
              background: T.info,
            }}
          >
            <Icon name="activity" size={14} color="#fff" />
            {wifiChecking ? "Checking..." : "Test Wi-Fi Connection"}
          </button>

          <span
            style={{
              display: "inline-flex",
              alignItems: "center",
              gap: 8,
              background:
                wifiStatus.state === "Connected"
                  ? T.primaryLight
                  : T.dangerLight,
              color: wifiStatus.state === "Connected" ? T.primary : T.danger,
              padding: "6px 12px",
              borderRadius: 8,
              fontSize: 12,
              fontWeight: 600,
            }}
          >
            <span
              style={{
                width: 8,
                height: 8,
                borderRadius: "50%",
                background:
                  wifiStatus.state === "Connected" ? T.primary : T.danger,
                display: "inline-block",
              }}
            />
            ESP32 Wi-Fi: {wifiStatus.state}
          </span>
          <span style={{ fontSize: 11, color: T.textMuted }}>
            {wifiStatus.detail}
          </span>
        </div>
      </div>

      <div style={panelStyle}>
        <SectionHead
          title="ESP32 Endpoint Integration"
          desc="Configure MQTT broker and API endpoint for telemetry and command sync"
          icon="activity"
          color={T.info}
        />
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(3,1fr)",
            gap: 14,
            marginBottom: 16,
          }}
        >
          <Field label="Primary Transport">
            <select
              style={selectS}
              value={endpoint.transport}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, transport: e.target.value }))
              }
            >
              <option value="mqtt">MQTT</option>
              <option value="https">HTTPS API</option>
              <option value="hybrid">Hybrid (MQTT + API)</option>
            </select>
          </Field>
          <Field label="MQTT Broker Host">
            <input
              style={inputS}
              value={endpoint.brokerHost}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, brokerHost: e.target.value }))
              }
              placeholder="e.g. broker.hivemq.com"
            />
          </Field>
          <Field label="MQTT Port">
            <input
              style={inputS}
              value={endpoint.brokerPort}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, brokerPort: e.target.value }))
              }
              placeholder="e.g. 1883"
            />
          </Field>

          <Field label="Publish Topic">
            <input
              style={inputS}
              value={endpoint.topicPublish}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, topicPublish: e.target.value }))
              }
              placeholder="e.g. sukat/esp32/measurements"
            />
          </Field>
          <Field label="Subscribe Topic">
            <input
              style={inputS}
              value={endpoint.topicSubscribe}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, topicSubscribe: e.target.value }))
              }
              placeholder="e.g. sukat/esp32/commands"
            />
          </Field>
          <Field label="Device ID">
            <input
              style={inputS}
              value={endpoint.deviceId}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, deviceId: e.target.value }))
              }
              placeholder="e.g. ESP32-KIOSK-01"
            />
          </Field>

          <Field label="API Base URL">
            <input
              style={inputS}
              value={endpoint.apiBaseUrl}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, apiBaseUrl: e.target.value }))
              }
              placeholder="e.g. https://eopt.doh.gov.ph/api"
            />
          </Field>
          <Field label="API Token / Key">
            <input
              type="password"
              style={inputS}
              value={endpoint.apiToken}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, apiToken: e.target.value }))
              }
              placeholder="Enter API token"
            />
          </Field>
          <Field label="Request Timeout (ms)">
            <input
              style={inputS}
              value={endpoint.timeoutMs}
              onChange={(e) =>
                setEndpoint((p) => ({ ...p, timeoutMs: e.target.value }))
              }
              placeholder="e.g. 5000"
            />
          </Field>
        </div>

        <div
          style={{
            display: "flex",
            alignItems: "center",
            gap: 10,
            flexWrap: "wrap",
          }}
        >
          <button
            onClick={() =>
              showToast("ESP32 endpoint settings saved", "success")
            }
            style={btnPrimary}
          >
            <Icon name="save" size={14} color="#fff" />
            Save Endpoint Settings
          </button>

          <button
            onClick={() => {
              setEndpointTesting("mqtt");
              setTimeout(() => {
                const ok = Math.random() > 0.12;
                setEndpointStatus((prev) => ({
                  ...prev,
                  mqtt: ok ? "Online" : "Offline",
                  mqttDetail: ok
                    ? `Connected ${endpoint.brokerHost}:${endpoint.brokerPort} · Topic OK`
                    : "Broker timeout. Verify host/port/firewall.",
                }));
                setEndpointTesting("");
                showToast(
                  ok ? "MQTT broker reachable" : "MQTT broker unavailable",
                  ok ? "success" : "danger",
                );
              }, 1200);
            }}
            style={{ ...btnPrimary, background: T.primaryMid }}
          >
            <Icon name="signal" size={14} color="#fff" />
            {endpointTesting === "mqtt" ? "Testing MQTT..." : "Test MQTT"}
          </button>

          <button
            onClick={() => {
              setEndpointTesting("api");
              setTimeout(() => {
                const ok = Math.random() > 0.1;
                setEndpointStatus((prev) => ({
                  ...prev,
                  api: ok ? "Online" : "Offline",
                  apiDetail: ok
                    ? `HTTP 200 · ${endpoint.apiBaseUrl}/health`
                    : "API request failed. Check base URL/token.",
                }));
                setEndpointTesting("");
                showToast(
                  ok ? "API endpoint reachable" : "API endpoint failed",
                  ok ? "success" : "danger",
                );
              }, 1200);
            }}
            style={{ ...btnPrimary, background: T.info }}
          >
            <Icon name="activity" size={14} color="#fff" />
            {endpointTesting === "api" ? "Testing API..." : "Test API"}
          </button>

          <span
            style={{
              background:
                endpointStatus.mqtt === "Online"
                  ? T.primaryLight
                  : T.dangerLight,
              color: endpointStatus.mqtt === "Online" ? T.primary : T.danger,
              padding: "6px 10px",
              borderRadius: 8,
              fontSize: 11,
              fontWeight: 700,
            }}
          >
            MQTT: {endpointStatus.mqtt}
          </span>
          <span
            style={{
              background:
                endpointStatus.api === "Online" ? T.infoLight : T.dangerLight,
              color: endpointStatus.api === "Online" ? T.info : T.danger,
              padding: "6px 10px",
              borderRadius: 8,
              fontSize: 11,
              fontWeight: 700,
            }}
          >
            API: {endpointStatus.api}
          </span>
        </div>
      </div>

      <div style={panelStyle}>
        <div
          style={{
            fontWeight: 700,
            fontSize: 15,
            color: T.text,
            marginBottom: 16,
          }}
        >
          Sensor Connection Status
        </div>
        <div
          style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}
        >
          {[
            {
              name: "HX711 Load Cell",
              port: "/dev/ttyUSB0",
              status: "Connected",
              reading: "0.00 kg (tare)",
            },
            {
              name: "TF-Luna LiDAR",
              port: "/dev/ttyUSB1",
              status: "Connected",
              reading: "0.00 cm (idle)",
            },
            {
              name: "ESP32 Wi-Fi",
              port: wifi.ipMode === "dhcp" ? "DHCP" : wifi.staticIp,
              status: wifiStatus.state,
              reading: wifiStatus.detail,
            },
            {
              name: "MQTT Broker",
              port: `${endpoint.brokerHost}:${endpoint.brokerPort}`,
              status: endpointStatus.mqtt,
              reading: endpointStatus.mqttDetail,
            },
            {
              name: "API Gateway",
              port: "HTTPS",
              status: endpointStatus.api,
              reading: endpointStatus.apiDetail,
            },
            {
              name: "eOPT+ Sync",
              port: "HTTPS",
              status: "Online",
              reading: "Last sync: 5 min ago",
            },
          ].map((s) => (
            <div
              key={s.name}
              style={{
                background: T.bg,
                borderRadius: 12,
                padding: 14,
                display: "flex",
                alignItems: "center",
                gap: 12,
              }}
            >
              <div
                style={{
                  width: 8,
                  height: 8,
                  borderRadius: "50%",
                  background:
                    s.status === "Connected" || s.status === "Online"
                      ? T.primaryMid
                      : T.danger,
                  flexShrink: 0,
                }}
              />
              <div>
                <div style={{ fontSize: 12, fontWeight: 600, color: T.text }}>
                  {s.name}
                </div>
                <div style={{ fontSize: 11, color: T.textMuted }}>
                  {s.port} · {s.reading}
                </div>
              </div>
              <span
                style={{
                  marginLeft: "auto",
                  fontSize: 10,
                  color: T.primaryMid,
                  fontWeight: 600,
                }}
              >
                {s.status}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function AuditLogs({ logs }) {
  const [filter, setFilter] = useState("all");
  const [search, setSearch] = useState("");
  const filtered = logs.filter(
    (l) =>
      (filter === "all" || l.level === filter) &&
      (l.action + l.detail + l.user)
        .toLowerCase()
        .includes(search.toLowerCase()),
  );
  const levelColor = { info: T.info, warn: T.warn, danger: T.danger };
  const levelBg = {
    info: T.infoLight,
    warn: T.warnLight,
    danger: T.dangerLight,
  };

  return (
    <div>
      <PageHeader
        title="Audit Logs"
        subtitle={`${logs.length} system events recorded`}
      />
      <div
        style={{ display: "flex", gap: 10, marginBottom: 16, flexWrap: "wrap" }}
      >
        <input
          placeholder="Search logs..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          style={{
            flex: 1,
            minWidth: 200,
            padding: "9px 14px",
            border: `1px solid ${T.border}`,
            borderRadius: 10,
            fontSize: 13,
            outline: "none",
            background: T.card,
          }}
        />
        {["all", "info", "warn", "danger"].map((f) => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            style={{
              padding: "8px 14px",
              borderRadius: 20,
              fontSize: 11,
              fontWeight: 600,
              cursor: "pointer",
              border: `1px solid ${filter === f ? levelColor[f] || T.primary : T.border}`,
              background: filter === f ? levelBg[f] || T.primaryLight : T.card,
              color: filter === f ? levelColor[f] || T.primary : T.textMuted,
              textTransform: "capitalize",
            }}
          >
            {f}
          </button>
        ))}
      </div>
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          border: `1px solid ${T.border}`,
          overflow: "hidden",
        }}
      >
        <table style={{ width: "100%", borderCollapse: "collapse" }}>
          <thead>
            <tr style={{ background: T.bg }}>
              {["Level", "Timestamp", "User", "Action", "Detail"].map((h) => (
                <th
                  key={h}
                  style={{
                    textAlign: "left",
                    padding: "10px 16px",
                    fontSize: 11,
                    color: T.textMuted,
                    fontWeight: 600,
                    borderBottom: `1px solid ${T.border}`,
                  }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {filtered.map((log, i) => (
              <tr
                key={log.id}
                style={{
                  borderBottom:
                    i < filtered.length - 1 ? `1px solid ${T.border}` : "none",
                }}
              >
                <td style={{ padding: "10px 16px" }}>
                  <span
                    style={{
                      background: levelBg[log.level],
                      color: levelColor[log.level],
                      fontSize: 10,
                      fontWeight: 700,
                      padding: "2px 8px",
                      borderRadius: 20,
                      textTransform: "uppercase",
                    }}
                  >
                    {log.level}
                  </span>
                </td>
                <td
                  style={{
                    padding: "10px 16px",
                    fontSize: 11,
                    color: T.textMuted,
                    whiteSpace: "nowrap",
                  }}
                >
                  {log.timestamp}
                </td>
                <td
                  style={{
                    padding: "10px 16px",
                    fontSize: 12,
                    fontFamily: "monospace",
                    color: T.text,
                  }}
                >
                  {log.user}
                </td>
                <td
                  style={{
                    padding: "10px 16px",
                    fontSize: 12,
                    fontWeight: 600,
                    color: T.text,
                  }}
                >
                  {log.action}
                </td>
                <td
                  style={{
                    padding: "10px 16px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {log.detail}
                </td>
              </tr>
            ))}
            {filtered.length === 0 && (
              <tr>
                <td
                  colSpan={5}
                  style={{
                    padding: 32,
                    textAlign: "center",
                    color: T.textMuted,
                    fontSize: 13,
                  }}
                >
                  No logs match your search.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function RolesPermissions({ users, onUpdateUserRole, showToast }) {
  const ROLE_META = {
    admin: {
      label: "Admin",
      color: T.purple,
      bg: T.purpleLight,
      desc: "Controls security, users, hardware, and system configuration.",
    },
    nutritionist: {
      label: "Nutritionist",
      color: T.primaryMid,
      bg: T.primaryLight,
      desc: "Handles child monitoring, appointments, and health analysis.",
    },
    kiosk: {
      label: "Kiosk",
      color: T.info,
      bg: T.infoLight,
      desc: "Field measurement role with controlled and guided access.",
    },
  };

  const PERMISSION_CATALOG = [
    { key: "dashboard", label: "Dashboard", area: "Core" },
    { key: "user_management", label: "User Management", area: "Security" },
    {
      key: "roles_permissions",
      label: "Roles & Permissions",
      area: "Security",
    },
    { key: "audit_logs", label: "Audit Logs", area: "Security" },
    {
      key: "sensor_calibration",
      label: "Sensor Calibration",
      area: "Hardware",
    },
    { key: "system_settings", label: "System Settings", area: "Config" },
    { key: "children_growth", label: "Children & Growth", area: "Health" },
    { key: "measurements", label: "Measurements", area: "Health" },
    { key: "who_analysis", label: "WHO Analysis", area: "Health" },
    { key: "parents", label: "Parents", area: "Community" },
    { key: "appointments", label: "Appointments", area: "Community" },
    { key: "reports", label: "Reports", area: "Reporting" },
  ];

  const [selectedRole, setSelectedRole] = useState("admin");
  const [permissionSearch, setPermissionSearch] = useState("");
  const [assignUserId, setAssignUserId] = useState(users[0]?.id || "");
  const [assignRole, setAssignRole] = useState("nutritionist");
  const [rolePolicies, setRolePolicies] = useState({
    admin: {
      dashboard: true,
      user_management: true,
      roles_permissions: true,
      audit_logs: true,
      sensor_calibration: true,
      system_settings: true,
      children_growth: false,
      measurements: false,
      who_analysis: false,
      parents: false,
      appointments: false,
      reports: false,
    },
    nutritionist: {
      dashboard: true,
      user_management: false,
      roles_permissions: false,
      audit_logs: false,
      sensor_calibration: false,
      system_settings: false,
      children_growth: true,
      measurements: true,
      who_analysis: true,
      parents: true,
      appointments: true,
      reports: true,
    },
    kiosk: {
      dashboard: false,
      user_management: false,
      roles_permissions: false,
      audit_logs: false,
      sensor_calibration: false,
      system_settings: false,
      children_growth: true,
      measurements: true,
      who_analysis: true,
      parents: false,
      appointments: false,
      reports: false,
    },
  });
  const [policyLogs, setPolicyLogs] = useState([
    {
      id: 1,
      ts: new Date().toLocaleString("en-PH"),
      action: "Policy initialized",
      detail: "Baseline role policies loaded.",
      actor: "System",
    },
  ]);

  const roleKeys = Object.keys(ROLE_META);
  const usersByRole = users.reduce((acc, u) => {
    const key = u.role || "nutritionist";
    acc[key] = (acc[key] || 0) + 1;
    return acc;
  }, {});

  const filteredPermissions = PERMISSION_CATALOG.filter(
    (p) =>
      p.label.toLowerCase().includes(permissionSearch.toLowerCase()) ||
      p.area.toLowerCase().includes(permissionSearch.toLowerCase()),
  );

  const enabledCount = Object.values(rolePolicies[selectedRole]).filter(
    Boolean,
  ).length;

  const togglePermission = (key) => {
    const nextValue = !rolePolicies[selectedRole][key];
    setRolePolicies((prev) => ({
      ...prev,
      [selectedRole]: {
        ...prev[selectedRole],
        [key]: nextValue,
      },
    }));

    const permissionName =
      PERMISSION_CATALOG.find((p) => p.key === key)?.label || key;
    const verb = nextValue ? "Enabled" : "Disabled";
    setPolicyLogs((prev) => [
      {
        id: prev.length + 1,
        ts: new Date().toLocaleString("en-PH"),
        action: `${verb} permission`,
        detail: `${permissionName} for ${ROLE_META[selectedRole].label}`,
        actor: "Admin",
      },
      ...prev,
    ]);
    showToast(`${permissionName} ${nextValue ? "enabled" : "disabled"}`);
  };

  const handleAssignRole = () => {
    const targetUser = users.find((u) => u.id === assignUserId);
    if (!targetUser) return;

    onUpdateUserRole(targetUser.id, assignRole);
    setPolicyLogs((prev) => [
      {
        id: prev.length + 1,
        ts: new Date().toLocaleString("en-PH"),
        action: "Role reassigned",
        detail: `${targetUser.name} moved to ${ROLE_META[assignRole].label}`,
        actor: "Admin",
      },
      ...prev,
    ]);
    showToast(`${targetUser.name} updated to ${ROLE_META[assignRole].label}`);
  };

  return (
    <div>
      <PageHeader
        title="Roles & Permissions"
        subtitle="Manage access policy, assign user roles, and track security changes"
      />

      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(3,minmax(0,1fr))",
          gap: 12,
          marginBottom: 16,
        }}
      >
        <div
          style={{
            background: T.card,
            border: `1px solid ${T.border}`,
            borderRadius: 12,
            padding: 14,
          }}
        >
          <div style={{ fontSize: 11, color: T.textMuted }}>
            Configured Roles
          </div>
          <div style={{ fontSize: 24, fontWeight: 800, color: T.text }}>
            {roleKeys.length}
          </div>
        </div>
        <div
          style={{
            background: T.card,
            border: `1px solid ${T.border}`,
            borderRadius: 12,
            padding: 14,
          }}
        >
          <div style={{ fontSize: 11, color: T.textMuted }}>
            Active Permissions ({ROLE_META[selectedRole].label})
          </div>
          <div style={{ fontSize: 24, fontWeight: 800, color: T.primary }}>
            {enabledCount}
          </div>
        </div>
        <div
          style={{
            background: T.card,
            border: `1px solid ${T.border}`,
            borderRadius: 12,
            padding: 14,
          }}
        >
          <div style={{ fontSize: 11, color: T.textMuted }}>
            Users in {ROLE_META[selectedRole].label}
          </div>
          <div style={{ fontSize: 24, fontWeight: 800, color: T.info }}>
            {usersByRole[selectedRole] || 0}
          </div>
        </div>
      </div>

      <div
        style={{ display: "flex", gap: 10, marginBottom: 12, flexWrap: "wrap" }}
      >
        {roleKeys.map((key) => (
          <button
            key={key}
            onClick={() => setSelectedRole(key)}
            style={{
              borderRadius: 999,
              border: `1px solid ${ROLE_META[key].color}33`,
              background: selectedRole === key ? ROLE_META[key].bg : T.card,
              color: ROLE_META[key].color,
              padding: "8px 14px",
              fontSize: 12,
              fontWeight: 700,
              cursor: "pointer",
            }}
          >
            {ROLE_META[key].label} ({usersByRole[key] || 0})
          </button>
        ))}
      </div>

      <div style={{ fontSize: 13, color: T.textMuted, marginBottom: 16 }}>
        {ROLE_META[selectedRole].desc}
      </div>

      <div
        style={{
          display: "grid",
          gridTemplateColumns: "1.2fr 0.8fr",
          gap: 16,
          marginBottom: 16,
        }}
      >
        <div
          style={{
            background: T.card,
            border: `1px solid ${T.border}`,
            borderRadius: 14,
            overflow: "hidden",
          }}
        >
          <div
            style={{
              padding: 14,
              borderBottom: `1px solid ${T.border}`,
              display: "flex",
              alignItems: "center",
              justifyContent: "space-between",
              gap: 12,
            }}
          >
            <div style={{ fontSize: 14, fontWeight: 700, color: T.text }}>
              Permission Matrix
            </div>
            <input
              value={permissionSearch}
              onChange={(e) => setPermissionSearch(e.target.value)}
              placeholder="Search permission or area"
              style={{
                border: `1px solid ${T.border}`,
                borderRadius: 8,
                padding: "8px 10px",
                fontSize: 12,
                width: 220,
                outline: "none",
              }}
            />
          </div>
          <div style={{ padding: 10, display: "grid", gap: 8 }}>
            {filteredPermissions.map((p) => (
              <div
                key={p.key}
                style={{
                  border: `1px solid ${T.border}`,
                  borderRadius: 10,
                  padding: "10px 12px",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "space-between",
                }}
              >
                <div>
                  <div style={{ fontSize: 13, fontWeight: 600, color: T.text }}>
                    {p.label}
                  </div>
                  <div style={{ fontSize: 11, color: T.textMuted }}>
                    {p.area}
                  </div>
                </div>
                <label
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 8,
                    fontSize: 12,
                    color: T.textMuted,
                    cursor: "pointer",
                  }}
                >
                  <input
                    type="checkbox"
                    checked={!!rolePolicies[selectedRole][p.key]}
                    onChange={() => togglePermission(p.key)}
                  />
                  {rolePolicies[selectedRole][p.key] ? "Allowed" : "Blocked"}
                </label>
              </div>
            ))}
          </div>
        </div>

        <div style={{ display: "grid", gap: 16, alignContent: "start" }}>
          <div
            style={{
              background: T.card,
              border: `1px solid ${T.border}`,
              borderRadius: 14,
              padding: 14,
            }}
          >
            <div
              style={{
                fontSize: 14,
                fontWeight: 700,
                color: T.text,
                marginBottom: 12,
              }}
            >
              Assign Role to User
            </div>
            <div style={{ display: "grid", gap: 10 }}>
              <select
                style={selectS}
                value={assignUserId}
                onChange={(e) => setAssignUserId(Number(e.target.value))}
              >
                {users.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name} ({u.email})
                  </option>
                ))}
              </select>
              <select
                style={selectS}
                value={assignRole}
                onChange={(e) => setAssignRole(e.target.value)}
              >
                {roleKeys.map((k) => (
                  <option key={k} value={k}>
                    {ROLE_META[k].label}
                  </option>
                ))}
              </select>
              <button onClick={handleAssignRole} style={btnPrimary}>
                <Icon name="edit" size={13} color="#fff" /> Apply Role Change
              </button>
            </div>
          </div>

          <div
            style={{
              background: T.card,
              border: `1px solid ${T.border}`,
              borderRadius: 14,
              padding: 14,
            }}
          >
            <div
              style={{
                fontSize: 14,
                fontWeight: 700,
                color: T.text,
                marginBottom: 10,
              }}
            >
              Current Users in Role
            </div>
            <div
              style={{
                display: "grid",
                gap: 8,
                maxHeight: 260,
                overflow: "auto",
              }}
            >
              {users
                .filter((u) => u.role === selectedRole)
                .map((u) => (
                  <div
                    key={u.id}
                    style={{
                      border: `1px solid ${T.border}`,
                      borderRadius: 10,
                      padding: "8px 10px",
                    }}
                  >
                    <div
                      style={{ fontSize: 12, fontWeight: 700, color: T.text }}
                    >
                      {u.name}
                    </div>
                    <div style={{ fontSize: 11, color: T.textMuted }}>
                      {u.email}
                    </div>
                  </div>
                ))}
            </div>
          </div>
        </div>
      </div>

      <div
        style={{
          background: T.card,
          border: `1px solid ${T.border}`,
          borderRadius: 14,
          overflow: "hidden",
        }}
      >
        <div
          style={{
            padding: "12px 14px",
            borderBottom: `1px solid ${T.border}`,
            fontSize: 14,
            fontWeight: 700,
            color: T.text,
          }}
        >
          Recent Access Policy Changes
        </div>
        <table style={{ width: "100%", borderCollapse: "collapse" }}>
          <thead>
            <tr style={{ background: T.bg }}>
              {["Timestamp", "Action", "Details", "Actor"].map((h) => (
                <th
                  key={h}
                  style={{
                    textAlign: "left",
                    padding: "10px 14px",
                    fontSize: 11,
                    color: T.textMuted,
                    fontWeight: 700,
                    borderBottom: `1px solid ${T.border}`,
                  }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {policyLogs.slice(0, 8).map((log, i) => (
              <tr
                key={log.id}
                style={{
                  borderBottom: i < 7 ? `1px solid ${T.border}` : "none",
                }}
              >
                <td
                  style={{
                    padding: "10px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {log.ts}
                </td>
                <td
                  style={{ padding: "10px 14px", fontSize: 12, color: T.text }}
                >
                  {log.action}
                </td>
                <td
                  style={{
                    padding: "10px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {log.detail}
                </td>
                <td
                  style={{
                    padding: "10px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {log.actor}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function AdminSystemSettings({ showToast }) {
  const [settings, setSettings] = useState([
    {
      key: "who_reference_year",
      value: "2006",
      desc: "WHO Child Growth Standards reference year",
    },
    {
      key: "kiosk_timeout_seconds",
      value: "120",
      desc: "Kiosk session timeout in seconds",
    },
    {
      key: "eopt_sync_url",
      value: "https://eopt.doh.gov.ph/api",
      desc: "eOPT+ national sync endpoint",
    },
    {
      key: "alert_threshold_waz",
      value: "-2.0",
      desc: "Alert threshold for Weight-for-Age Z-score",
    },
    {
      key: "barangay_coverage",
      value: "Bagong Silang, Poblacion, San Jose, Sta. Cruz",
      desc: "Covered barangays",
    },
    {
      key: "auto_notify_parents",
      value: "true",
      desc: "Automatically notify parents on new results",
    },
    {
      key: "backup_interval_hours",
      value: "6",
      desc: "Automated database backup interval (hours)",
    },
    {
      key: "sync_interval_minutes",
      value: "5",
      desc: "eOPT+ data synchronization interval",
    },
  ]);
  const [saved, setSaved] = useState(null);

  return (
    <div>
      <PageHeader
        title="System Settings"
        subtitle="Configure global system parameters"
      />
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          border: `1px solid ${T.border}`,
          overflow: "hidden",
        }}
      >
        {settings.map((s, i) => (
          <div
            key={s.key}
            style={{
              display: "grid",
              gridTemplateColumns: "260px 1fr 140px",
              gap: 16,
              alignItems: "center",
              padding: "16px 20px",
              borderBottom:
                i < settings.length - 1 ? `1px solid ${T.border}` : "none",
            }}
          >
            <div>
              <div
                style={{
                  fontFamily: "monospace",
                  fontSize: 12,
                  color: T.primary,
                  marginBottom: 2,
                }}
              >
                {s.key}
              </div>
              <div style={{ fontSize: 11, color: T.textMuted }}>{s.desc}</div>
            </div>
            <input
              value={s.value}
              onChange={(e) =>
                setSettings((prev) =>
                  prev.map((x, xi) =>
                    xi === i ? { ...x, value: e.target.value } : x,
                  ),
                )
              }
              style={inputS}
            />
            <button
              onClick={() => {
                setSaved(s.key);
                showToast("Setting saved");
              }}
              style={{
                background: T.primaryLight,
                color: T.primary,
                border: "none",
                borderRadius: 8,
                padding: "8px 16px",
                fontSize: 12,
                fontWeight: 600,
                cursor: "pointer",
              }}
            >
              {saved === s.key ? (
                <span>
                  <Icon
                    name="check"
                    size={12}
                    color="#fff"
                    style={{ display: "inline-block", marginRight: 6 }}
                  />{" "}
                  Saved
                </span>
              ) : (
                "Update"
              )}
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}

function NutritionistDashboard({ children, parents, appointments }) {
  const [tableFilter, setTableFilter] = useState("Today");
  const [calendarMonth, setCalendarMonth] = useState(new Date(2026, 4, 1)); // May 2026
  const [hoveredCol, setHoveredCol] = useState(null);
  const [tooltipPos, setTooltipPos] = useState({ x: 0, y: 0 });
  const [selectedRows, setSelectedRows] = useState({});
  const [selectAll, setSelectAll] = useState(false);

  // ── Derived stats ──────────────────────────────────────────────────────────
  const atRisk = children.filter(
    (c) => !["Normal", "Overweight"].includes(c.status),
  );
  const statusCounts = children.reduce((acc, c) => {
    acc[c.status] = (acc[c.status] || 0) + 1;
    return acc;
  }, {});
  const todayAppts = appointments.filter(
    (a) => a.status === "Scheduled",
  ).length;
  const activeParents = parents.filter((p) => p.status === "Active").length;

  // ── Stat cards ──────────────────────────────────────────────────────────────
  const STAT_CARDS = [
    {
      label: "Children Monitored",
      value: children.length,
      icon: PATHS.children,
      iconColor: "#fff",
      iconBg: "rgba(255,255,255,.2)",
      numColor: "#fff",
      deltaColor: "rgba(255,255,255,.8)",
      descColor: "rgba(255,255,255,.55)",
      delta: "↑ +15.9%",
      desc: "Registered children",
      featured: true,
      miniStroke: "#fff",
      miniPoints: "0,35 15,25 30,30 45,15 60,20 80,5",
    },
    {
      label: "At-Risk Cases",
      value: atRisk.length,
      icon: PATHS.alertTriangle,
      iconColor: T.danger,
      iconBg: T.dangerLight,
      numColor: T.danger,
      deltaColor: T.danger,
      delta: "↑ Need attention",
      desc: "Priority follow-up",
      featured: false,
      miniStroke: T.danger,
      miniPoints: "0,20 15,32 30,18 45,28 60,12 80,22",
    },
    {
      label: "Parents Linked",
      value: parents.length,
      icon: PATHS.parents,
      iconColor: T.info,
      iconBg: T.infoLight,
      numColor: T.info,
      deltaColor: T.info,
      delta: `↑ ${activeParents} active`,
      desc: "Guardian accounts",
      featured: false,
      miniStroke: T.info,
      miniPoints: "0,30 15,22 30,28 45,12 60,18 80,8",
    },
    {
      label: "Appointments",
      value: todayAppts,
      icon: PATHS.calendar,
      iconColor: T.purple,
      iconBg: T.purpleLight,
      numColor: T.purple,
      deltaColor: T.purple,
      delta: "↑ Upcoming",
      desc: "Scheduled visits",
      featured: false,
      miniStroke: T.purple,
      miniPoints: "0,28 15,18 30,24 45,10 60,16 80,6",
    },
  ];

  // ── Chart data (multi-line: Normal, Severe, Underweight, Stunted) ──────────
  const MONTHS_LABELS = [
    "Oct",
    "Nov",
    "Dec",
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "May",
  ];
  const CHART_XS = [56, 110, 164, 218, 272, 326, 380, 420];
  const CHART_DATA = {
    Normal: [59, 65, 61, 72, 68, 71, 64, 66],
    Severe: [10, 12, 11, 14, 13, 15, 12, 13],
    Underweight: [18, 22, 20, 26, 24, 28, 22, 24],
    Stunted: [14, 18, 16, 20, 18, 22, 18, 20],
  };
  const CHART_COLORS = {
    Normal: "#1A8F68",
    Severe: "#E03131",
    Underweight: "#E67E22",
    Stunted: "#7048E8",
  };
  // Convert data values → SVG y (range 10–152, domain 0–100)
  const toY = (v) => 152 - (v / 100) * 136;
  const makePoints = (arr) =>
    arr.map((v, i) => `${CHART_XS[i]},${toY(v)}`).join(" ");

  // ── Calendar ────────────────────────────────────────────────────────────────
  const MONTH_NAMES = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
  ];

  // Build event map for calendar — appointments + Oplan Timbang
  const apptByDay = appointments
    .filter((a) => {
      const d = new Date(a.date);
      return (
        d.getFullYear() === calendarMonth.getFullYear() &&
        d.getMonth() === calendarMonth.getMonth()
      );
    })
    .reduce((acc, a) => {
      const day = new Date(a.date).getDate();
      if (!acc[day]) acc[day] = [];
      acc[day].push({ color: "#E03131", label: "Appointment" });
      return acc;
    }, {});

  // Oplan Timbang — fixed days in any month (6, 13, 20, 27 = every Wednesday-ish)
  const optDays = [6, 13, 20, 27];
  optDays.forEach((d) => {
    if (!apptByDay[d]) apptByDay[d] = [];
    apptByDay[d].push({ color: "#1A8F68", label: "Oplan Timbang" });
  });

  // Meeting events
  [11, 16].forEach((d) => {
    if (!apptByDay[d]) apptByDay[d] = [];
    apptByDay[d].push({ color: T.info, label: "Meeting" });
  });

  const firstWeekday = new Date(
    calendarMonth.getFullYear(),
    calendarMonth.getMonth(),
    1,
  ).getDay();
  const daysInMonth = new Date(
    calendarMonth.getFullYear(),
    calendarMonth.getMonth() + 1,
    0,
  ).getDate();
  const today = new Date();
  const isCurrentMonth =
    today.getMonth() === calendarMonth.getMonth() &&
    today.getFullYear() === calendarMonth.getFullYear();

  const calCells = [
    ...Array(firstWeekday).fill(null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];
  while (calCells.length % 7 !== 0) calCells.push(null);

  // ── Alerts ──────────────────────────────────────────────────────────────────
  const ALERTS = [
    {
      child: "Sofia Garcia",
      status: "Severely Underweight",
      msg: "WAZ -3.2 — immediate intervention needed",
      color: T.danger,
      bg: T.dangerLight,
      iconPath: PATHS.alertTriangle,
    },
    {
      child: "Juan Dela Cruz",
      status: "Underweight",
      msg: "2nd consecutive underweight reading",
      color: T.warn,
      bg: T.warnLight,
      iconPath: PATHS.alertTriangle,
    },
    {
      child: "Carlos Lim",
      status: "Wasted",
      msg: "WHZ -2.2 — dietary plan required",
      color: T.info,
      bg: T.infoLight,
      iconPath: PATHS.info,
    },
  ];

  // ── Nutritional Status bars ──────────────────────────────────────────────────
  const STATUS_BARS = [
    { status: "Normal", color: T.primary },
    { status: "Underweight", color: T.warn },
    { status: "Severely Underweight", color: T.danger },
    { status: "Stunted", color: T.purple },
    { status: "Wasted", color: T.info },
    { status: "Overweight", color: T.accent },
  ];

  // ── Patient table rows ────────────────────────────────────────────────────
  const TABLE_FILTERS = ["Today", "Weekly", "Monthly", "Yearly"];

  // ── Shared card style ─────────────────────────────────────────────────────
  const card = {
    background: T.card,
    borderRadius: 14,
    border: `1px solid ${T.border}`,
    padding: 16,
  };

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
      {/* ── Stat Cards Row ───────────────────────────────────────────────── */}
      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(4,1fr)",
          gap: 10,
        }}
      >
        {STAT_CARDS.map((s) => (
          <div
            key={s.label}
            style={{
              background: s.featured ? T.primaryMid : T.card,
              borderRadius: 14,
              border: `1px solid ${s.featured ? T.primaryMid : T.border}`,
              padding: "14px 16px",
              position: "relative",
              overflow: "hidden",
              cursor: "pointer",
            }}
          >
            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 7,
                marginBottom: 10,
              }}
            >
              <div
                style={{
                  width: 26,
                  height: 26,
                  borderRadius: 7,
                  background: s.iconBg,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  flexShrink: 0,
                }}
              >
                <svg
                  width={13}
                  height={13}
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke={s.iconColor}
                  strokeWidth={1.8}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d={s.icon} />
                </svg>
              </div>
              <span
                style={{
                  fontSize: 11,
                  color: s.featured ? "rgba(255,255,255,.75)" : T.textMuted,
                }}
              >
                {s.label}
              </span>
              {/* dots */}
              <svg
                style={{ marginLeft: "auto" }}
                width={14}
                height={14}
                viewBox="0 0 24 24"
                fill="none"
                stroke={s.featured ? "rgba(255,255,255,.5)" : T.textMuted}
                strokeWidth={1.8}
                strokeLinecap="round"
              >
                <circle cx={12} cy={12} r={1} />
                <circle cx={19} cy={12} r={1} />
                <circle cx={5} cy={12} r={1} />
              </svg>
            </div>
            <div
              style={{
                fontSize: 26,
                fontWeight: 700,
                color: s.numColor,
                lineHeight: 1,
              }}
            >
              {s.value}
            </div>
            <div
              style={{
                fontSize: 10,
                color: s.deltaColor,
                fontWeight: 600,
                marginTop: 2,
              }}
            >
              {s.delta}
            </div>
            <div
              style={{
                fontSize: 10,
                color: s.featured ? "rgba(255,255,255,.55)" : T.textMuted,
                marginTop: 1,
              }}
            >
              {s.desc}
            </div>
            <svg
              style={{
                position: "absolute",
                bottom: 0,
                right: 0,
                opacity: s.featured ? 0.18 : 0.07,
              }}
              width={80}
              height={40}
              viewBox="0 0 80 40"
            >
              <polyline
                points={s.miniPoints}
                fill="none"
                stroke={s.miniStroke}
                strokeWidth={2}
              />
            </svg>
          </div>
        ))}
      </div>

      {/* ── Mid Row: Chart + Calendar ─────────────────────────────────────── */}
      <div
        style={{ display: "grid", gridTemplateColumns: "1.6fr 1fr", gap: 12 }}
      >
        {/* Multi-line Malnutrition Chart */}
        <div style={card}>
          <div
            style={{
              display: "flex",
              alignItems: "flex-start",
              justifyContent: "space-between",
              marginBottom: 12,
            }}
          >
            <div>
              <div style={{ fontSize: 13, fontWeight: 600, color: T.text }}>
                Patient Overview
              </div>
              <div style={{ fontSize: 11, color: T.textMuted, marginTop: 2 }}>
                Malnutrition trends over time by classification
              </div>
            </div>
            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 10,
                flexWrap: "wrap",
              }}
            >
              {Object.entries(CHART_COLORS).map(([label, color]) => (
                <div
                  key={label}
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 5,
                    fontSize: 11,
                    color: T.textMuted,
                  }}
                >
                  <div
                    style={{
                      width: 7,
                      height: 7,
                      borderRadius: "50%",
                      background: color,
                      flexShrink: 0,
                    }}
                  />
                  {label}
                </div>
              ))}
            </div>
          </div>

          {/* SVG Chart with hover tooltip */}
          <div style={{ position: "relative" }}>
            {hoveredCol !== null && (
              <div
                style={{
                  position: "absolute",
                  left: tooltipPos.x,
                  top: tooltipPos.y,
                  background: T.card,
                  border: `1px solid ${T.border}`,
                  borderRadius: 8,
                  padding: "7px 11px",
                  fontSize: 11,
                  zIndex: 20,
                  whiteSpace: "nowrap",
                  boxShadow: "0 4px 12px rgba(0,0,0,.1)",
                  pointerEvents: "none",
                }}
              >
                <div
                  style={{ fontWeight: 600, color: T.text, marginBottom: 4 }}
                >
                  {MONTHS_LABELS[hoveredCol]}
                </div>
                {Object.entries(CHART_DATA).map(([label, arr]) => (
                  <div
                    key={label}
                    style={{
                      display: "flex",
                      alignItems: "center",
                      gap: 5,
                      marginTop: 2,
                      fontSize: 10,
                      color: T.textMuted,
                    }}
                  >
                    <div
                      style={{
                        width: 6,
                        height: 6,
                        borderRadius: "50%",
                        background: CHART_COLORS[label],
                        flexShrink: 0,
                      }}
                    />
                    {label}:{" "}
                    <span
                      style={{ fontWeight: 600, color: T.text, marginLeft: 2 }}
                    >
                      {arr[hoveredCol]}
                    </span>
                  </div>
                ))}
              </div>
            )}

            <svg
              width="100%"
              viewBox="0 0 460 195"
              style={{ display: "block", overflow: "visible" }}
              onMouseMove={(e) => {
                const rect = e.currentTarget.getBoundingClientRect();
                const mx = (e.clientX - rect.left) * (460 / rect.width);
                let ci = 0,
                  md = 9999;
                CHART_XS.forEach((x, i) => {
                  const d = Math.abs(x - mx);
                  if (d < md) {
                    md = d;
                    ci = i;
                  }
                });
                if (md > 35) {
                  setHoveredCol(null);
                  return;
                }
                setHoveredCol(ci);
                const px = e.clientX - rect.left;
                const py = e.clientY - rect.top;
                setTooltipPos({ x: px + 12, y: py - 10 });
              }}
              onMouseLeave={() => setHoveredCol(null)}
            >
              {/* Grid lines */}
              {[16, 50, 84, 118, 152].map((y) => (
                <line
                  key={y}
                  x1={44}
                  y1={y}
                  x2={430}
                  y2={y}
                  stroke={T.border}
                  strokeWidth={0.5}
                />
              ))}
              {/* Y labels */}
              {[
                ["100", 19],
                ["80", 53],
                ["60", 87],
                ["40", 121],
                ["20", 155],
              ].map(([lbl, y]) => (
                <text
                  key={y}
                  x={38}
                  y={y}
                  fontSize={9}
                  fill={T.textMuted}
                  textAnchor="end"
                >
                  {lbl}
                </text>
              ))}
              {/* Active hover band */}
              {hoveredCol !== null && (
                <rect
                  x={CHART_XS[hoveredCol] - 14}
                  y={10}
                  width={28}
                  height={142}
                  rx={4}
                  fill={T.primaryMid}
                  fillOpacity={0.08}
                />
              )}
              {/* Lines + dots */}
              {Object.entries(CHART_DATA).map(([label, arr]) => (
                <g key={label}>
                  <polyline
                    points={makePoints(arr)}
                    fill="none"
                    stroke={CHART_COLORS[label]}
                    strokeWidth={2.2}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                  {arr.map((v, i) => (
                    <circle
                      key={i}
                      cx={CHART_XS[i]}
                      cy={toY(v)}
                      r={hoveredCol === i ? 4.5 : 3.5}
                      fill={CHART_COLORS[label]}
                      stroke="#fff"
                      strokeWidth={1.5}
                    />
                  ))}
                </g>
              ))}
              {/* X labels */}
              {MONTHS_LABELS.map((m, i) => (
                <text
                  key={m}
                  x={CHART_XS[i]}
                  y={178}
                  fontSize={9}
                  fill={T.textMuted}
                  textAnchor="middle"
                >
                  {m}
                </text>
              ))}
            </svg>
          </div>
        </div>

        {/* Calendar with Oplan Timbang */}
        <div style={card}>
          <div
            style={{
              display: "flex",
              alignItems: "center",
              justifyContent: "space-between",
              marginBottom: 12,
            }}
          >
            <div style={{ fontSize: 13, fontWeight: 600, color: T.text }}>
              Calendar
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
              <button
                onClick={() =>
                  setCalendarMonth(
                    new Date(
                      calendarMonth.getFullYear(),
                      calendarMonth.getMonth() - 1,
                      1,
                    ),
                  )
                }
                style={{
                  background: "none",
                  border: `1px solid ${T.border}`,
                  borderRadius: "50%",
                  width: 24,
                  height: 24,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  cursor: "pointer",
                  color: T.textMuted,
                }}
              >
                <svg
                  width={10}
                  height={10}
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth={2.2}
                  strokeLinecap="round"
                >
                  <path d="M15 18l-6-6 6-6" />
                </svg>
              </button>
              <span
                style={{
                  fontSize: 12,
                  fontWeight: 500,
                  color: T.text,
                  minWidth: 110,
                  textAlign: "center",
                }}
              >
                {MONTH_NAMES[calendarMonth.getMonth()]}{" "}
                {calendarMonth.getFullYear()}
              </span>
              <button
                onClick={() =>
                  setCalendarMonth(
                    new Date(
                      calendarMonth.getFullYear(),
                      calendarMonth.getMonth() + 1,
                      1,
                    ),
                  )
                }
                style={{
                  background: "none",
                  border: `1px solid ${T.border}`,
                  borderRadius: "50%",
                  width: 24,
                  height: 24,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  cursor: "pointer",
                  color: T.textMuted,
                }}
              >
                <svg
                  width={10}
                  height={10}
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth={2.2}
                  strokeLinecap="round"
                >
                  <path d="M9 18l6-6-6-6" />
                </svg>
              </button>
            </div>
          </div>

          {/* Day headers */}
          <div
            style={{
              display: "grid",
              gridTemplateColumns: "repeat(7,1fr)",
              gap: 2,
              marginBottom: 4,
            }}
          >
            {["S", "M", "T", "W", "T", "F", "S"].map((d, i) => (
              <div
                key={`${d}-${i}`}
                style={{
                  textAlign: "center",
                  fontSize: 10,
                  color: T.textMuted,
                  padding: "3px 0",
                  fontWeight: 500,
                }}
              >
                {d}
              </div>
            ))}
          </div>

          {/* Calendar grid */}
          <div
            style={{
              display: "grid",
              gridTemplateColumns: "repeat(7,1fr)",
              gap: 3,
            }}
          >
            {calCells.map((day, i) => {
              if (!day) return <div key={`blank-${i}`} />;
              const isToday = isCurrentMonth && day === today.getDate();
              const events = apptByDay[day] || [];
              const hasEvents = events.length > 0;

              return (
                <div
                  key={`day-${day}`}
                  style={{
                    minHeight: 32,
                    borderRadius: 7,
                    display: "flex",
                    flexDirection: "column",
                    alignItems: "center",
                    justifyContent: "center",
                    cursor: hasEvents || isToday ? "pointer" : "default",
                    fontSize: 11,
                    padding: 2,
                    position: "relative",
                    background: isToday ? T.primary : "transparent",
                  }}
                >
                  <div
                    style={{
                      lineHeight: 1,
                      fontSize: 11,
                      color: isToday ? "#fff" : hasEvents ? T.primary : T.text,
                      fontWeight: isToday || hasEvents ? 600 : 400,
                      width: isToday ? 22 : "auto",
                      height: isToday ? 22 : "auto",
                      borderRadius: isToday ? "50%" : 0,
                      display: "flex",
                      alignItems: "center",
                      justifyContent: "center",
                      background: isToday ? T.primary : "transparent",
                    }}
                  >
                    {day}
                  </div>
                  {hasEvents && (
                    <div
                      style={{
                        display: "flex",
                        gap: 2,
                        marginTop: 2,
                        justifyContent: "center",
                      }}
                    >
                      {events.slice(0, 3).map((ev, ei) => (
                        <div
                          key={ei}
                          style={{
                            width: 4,
                            height: 4,
                            borderRadius: "50%",
                            background: isToday
                              ? "rgba(255,255,255,.8)"
                              : ev.color,
                          }}
                        />
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>

          {/* Calendar legend */}
          <div
            style={{
              display: "flex",
              gap: 10,
              marginTop: 12,
              flexWrap: "wrap",
              paddingTop: 10,
              borderTop: `1px solid ${T.border}`,
            }}
          >
            {[
              { color: "#E03131", label: "Appointment" },
              { color: T.info, label: "Meeting" },
              { color: T.primary, label: "Oplan Timbang" },
            ].map((l) => (
              <div
                key={l.label}
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 5,
                  fontSize: 10,
                  color: T.textMuted,
                }}
              >
                <div
                  style={{
                    width: 7,
                    height: 7,
                    borderRadius: "50%",
                    background: l.color,
                  }}
                />
                {l.label}
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* ── Alerts + Nutritional Status Row ──────────────────────────────── */}
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
        {/* Priority Alerts */}
        <div style={card}>
          <div
            style={{
              fontSize: 13,
              fontWeight: 600,
              color: T.text,
              marginBottom: 12,
            }}
          >
            Priority Alerts
          </div>
          {ALERTS.map((a) => (
            <div
              key={a.child}
              style={{
                display: "flex",
                gap: 10,
                padding: "10px 12px",
                borderRadius: 8,
                marginBottom: 8,
                alignItems: "flex-start",
                background: a.bg + "10",
                border: `1px solid ${a.color}20`,
              }}
            >
              <div
                style={{
                  width: 28,
                  height: 28,
                  borderRadius: 7,
                  background: a.bg,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  flexShrink: 0,
                }}
              >
                <svg
                  width={13}
                  height={13}
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke={a.color}
                  strokeWidth={1.8}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d={a.iconPath} />
                </svg>
              </div>
              <div>
                <div style={{ fontSize: 12, fontWeight: 600, color: T.text }}>
                  {a.child}
                </div>
                <div style={{ marginTop: 3 }}>
                  <StatusBadge status={a.status} />
                </div>
                <div
                  style={{
                    fontSize: 10,
                    color: T.textMuted,
                    marginTop: 4,
                    lineHeight: 1.4,
                  }}
                >
                  {a.msg}
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Nutritional Status bars */}
        <div style={card}>
          <div
            style={{
              fontSize: 13,
              fontWeight: 600,
              color: T.text,
              marginBottom: 12,
            }}
          >
            Nutritional Status
          </div>
          {STATUS_BARS.map((s) => {
            const count = statusCounts[s.status] || 0;
            const pct =
              children.length > 0
                ? Math.round((count / children.length) * 100)
                : 0;
            return (
              <div key={s.status} style={{ marginBottom: 10 }}>
                <div
                  style={{
                    display: "flex",
                    justifyContent: "space-between",
                    marginBottom: 4,
                    alignItems: "center",
                  }}
                >
                  <StatusBadge status={s.status} />
                  <span style={{ fontSize: 11, color: T.textMuted }}>
                    {count} ({pct}%)
                  </span>
                </div>
                <div
                  style={{
                    height: 7,
                    borderRadius: 999,
                    background: T.bg,
                    overflow: "hidden",
                  }}
                >
                  <div
                    style={{
                      width: `${Math.max(pct > 0 ? pct : 0, pct > 0 ? 3 : 0)}%`,
                      height: "100%",
                      borderRadius: 999,
                      background: s.color,
                    }}
                  />
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* ── Patient Overview Table ────────────────────────────────────────── */}
      <div style={card}>
        {/* Table header */}
        <div
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
            marginBottom: 12,
            flexWrap: "wrap",
            gap: 8,
          }}
        >
          <div>
            <div style={{ fontSize: 13, fontWeight: 600, color: T.text }}>
              Patient Overview
            </div>
            <div style={{ fontSize: 11, color: T.textMuted, marginTop: 2 }}>
              Registered children and their current growth status
            </div>
          </div>
          <div style={{ display: "flex", gap: 4 }}>
            {TABLE_FILTERS.map((f) => (
              <button
                key={f}
                onClick={() => setTableFilter(f)}
                style={{
                  border: `1px solid ${tableFilter === f ? T.primary : T.border}`,
                  background: tableFilter === f ? T.primary : T.card,
                  color: tableFilter === f ? "#fff" : T.textMuted,
                  borderRadius: 6,
                  padding: "5px 12px",
                  fontSize: 11,
                  cursor: "pointer",
                  fontWeight: tableFilter === f ? 600 : 400,
                  transition: "all .15s",
                }}
              >
                {f}
              </button>
            ))}
          </div>
        </div>

        {/* Table */}
        <div style={{ overflowX: "auto" }}>
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ background: T.bg }}>
                <th
                  style={{
                    padding: "8px 10px",
                    borderBottom: `1px solid ${T.border}`,
                  }}
                >
                  <input
                    type="checkbox"
                    checked={selectAll}
                    onChange={(e) => {
                      setSelectAll(e.target.checked);
                      const next = {};
                      if (e.target.checked)
                        children.forEach((c) => {
                          next[c.id] = true;
                        });
                      setSelectedRows(next);
                    }}
                    style={{ cursor: "pointer" }}
                  />
                </th>
                {[
                  "No",
                  "Name",
                  "Age",
                  "Date of Birth",
                  "Status",
                  "Barangay",
                  "Parent",
                  "Action",
                ].map((h) => (
                  <th
                    key={h}
                    style={{
                      textAlign: "left",
                      padding: "8px 10px",
                      fontSize: 10,
                      color: T.textMuted,
                      fontWeight: 500,
                      borderBottom: `1px solid ${T.border}`,
                      whiteSpace: "nowrap",
                    }}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {children.map((c, idx) => {
                const initials = `${c.first_name[0]}${c.last_name[0]}`;
                const dob = new Date(c.birthdate).toLocaleDateString("en-PH", {
                  day: "2-digit",
                  month: "short",
                  year: "numeric",
                });
                return (
                  <tr
                    key={c.id}
                    style={{
                      borderBottom:
                        idx < children.length - 1
                          ? `1px solid ${T.border}`
                          : "none",
                      background: selectedRows[c.id]
                        ? T.primaryLight + "80"
                        : "transparent",
                      transition: "background .1s",
                    }}
                  >
                    <td style={{ padding: "10px 10px" }}>
                      <input
                        type="checkbox"
                        checked={!!selectedRows[c.id]}
                        onChange={(e) =>
                          setSelectedRows((p) => ({
                            ...p,
                            [c.id]: e.target.checked,
                          }))
                        }
                        style={{ cursor: "pointer" }}
                      />
                    </td>
                    <td
                      style={{
                        padding: "10px 10px",
                        color: T.textMuted,
                        fontSize: 11,
                      }}
                    >
                      {String(idx + 1).padStart(2, "0")}
                    </td>
                    <td style={{ padding: "10px 10px" }}>
                      <div
                        style={{
                          display: "flex",
                          alignItems: "center",
                          gap: 8,
                        }}
                      >
                        <div
                          style={{
                            width: 28,
                            height: 28,
                            borderRadius: "50%",
                            background: sBg(c.status),
                            color: sColor(c.status),
                            display: "flex",
                            alignItems: "center",
                            justifyContent: "center",
                            flexShrink: 0,
                            fontSize: 10,
                            fontWeight: 600,
                          }}
                        >
                          {initials}
                        </div>
                        <div>
                          <div
                            style={{
                              fontWeight: 500,
                              fontSize: 12,
                              color: T.text,
                            }}
                          >
                            {c.first_name} {c.last_name}
                          </div>
                          <div
                            style={{
                              fontSize: 10,
                              color: T.textMuted,
                              marginTop: 1,
                            }}
                          >
                            {c.sex}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td
                      style={{
                        padding: "10px 10px",
                        color: T.textMuted,
                        fontSize: 12,
                      }}
                    >
                      {c.age_months} mo
                    </td>
                    <td
                      style={{
                        padding: "10px 10px",
                        color: T.textMuted,
                        fontSize: 12,
                        whiteSpace: "nowrap",
                      }}
                    >
                      {dob}
                    </td>
                    <td style={{ padding: "10px 10px" }}>
                      <StatusBadge status={c.status} />
                    </td>
                    <td
                      style={{
                        padding: "10px 10px",
                        color: T.textMuted,
                        fontSize: 12,
                      }}
                    >
                      {c.barangay}
                    </td>
                    <td
                      style={{
                        padding: "10px 10px",
                        color: T.textMuted,
                        fontSize: 12,
                      }}
                    >
                      {c.parent}
                    </td>
                    <td style={{ padding: "10px 10px" }}>
                      <button
                        title="Edit"
                        style={{
                          background: "none",
                          border: `1px solid ${T.border}`,
                          borderRadius: 6,
                          padding: "3px 7px",
                          cursor: "pointer",
                          color: T.textMuted,
                          marginRight: 3,
                          display: "inline-flex",
                          alignItems: "center",
                        }}
                      >
                        <svg
                          width={12}
                          height={12}
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth={1.8}
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        >
                          <path d={PATHS.edit} />
                        </svg>
                      </button>
                      <button
                        title="Delete"
                        style={{
                          background: "none",
                          border: `1px solid ${T.danger}30`,
                          borderRadius: 6,
                          padding: "3px 7px",
                          cursor: "pointer",
                          color: T.danger,
                          display: "inline-flex",
                          alignItems: "center",
                        }}
                      >
                        <svg
                          width={12}
                          height={12}
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke={T.danger}
                          strokeWidth={1.8}
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        >
                          <path d={PATHS.trash} />
                        </svg>
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function ChildrenMonitoring({ children, parents, measurements, showToast }) {
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("All");
  const [modal, setModal] = useState(null);
  const [viewChild, setViewChild] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    birthdate: "",
    sex: "Male",
    barangay: "",
    address: "",
    parent: "",
    age_months: "",
    status: "Normal",
  });

  const statuses = [
    "All",
    "Normal",
    "Underweight",
    "Severely Underweight",
    "Stunted",
    "Wasted",
    "Overweight",
  ];
  const filtered = children.filter(
    (c) =>
      (filter === "All" || c.status === filter) &&
      `${c.first_name} ${c.last_name} ${c.child_code}`
        .toLowerCase()
        .includes(search.toLowerCase()),
  );

  if (viewChild) {
    const childMeasurements = measurements.filter(
      (m) => m.child_id === viewChild.id,
    );
    const latest = childMeasurements[0];
    return (
      <div>
        <PageHeader
          title={`${viewChild.first_name} ${viewChild.last_name}`}
          subtitle={`${viewChild.child_code} · ${viewChild.barangay} · ${viewChild.age_months} months`}
          action={
            <button onClick={() => setViewChild(null)} style={btnSecondary}>
              <Icon
                name="arrowLeft"
                size={12}
                color="#6B8C7D"
                style={{ display: "inline-block", marginRight: 4 }}
              />{" "}
              Back
            </button>
          }
        />
        <div
          style={{ display: "grid", gridTemplateColumns: "300px 1fr", gap: 16 }}
        >
          <div
            style={{
              background: T.card,
              borderRadius: 16,
              border: `1px solid ${T.border}`,
              padding: 24,
            }}
          >
            <div style={{ textAlign: "center", marginBottom: 20 }}>
              <div
                style={{
                  display: "flex",
                  justifyContent: "center",
                  marginBottom: 12,
                }}
              >
                <ChildAvatar sex={viewChild.sex} size={64} />
              </div>
              <h2
                style={{
                  margin: 0,
                  fontSize: 16,
                  fontWeight: 700,
                  color: T.text,
                }}
              >
                {viewChild.first_name} {viewChild.last_name}
              </h2>
              <div
                style={{
                  color: T.textMuted,
                  fontSize: 12,
                  margin: "4px 0 10px",
                }}
              >
                {viewChild.child_code}
              </div>
              {latest && <StatusBadge status={latest.nutritional_status} />}
            </div>
            <div style={{ borderTop: `1px solid ${T.border}`, paddingTop: 16 }}>
              {[
                ["Birthdate", viewChild.birthdate],
                ["Age", `${viewChild.age_months} months`],
                ["Sex", viewChild.sex],
                ["Barangay", viewChild.barangay],
                ["Parent", viewChild.parent],
                ["Address", viewChild.address],
              ].map(([k, v]) => (
                <div
                  key={k}
                  style={{
                    display: "flex",
                    justifyContent: "space-between",
                    padding: "6px 0",
                    borderBottom: `1px solid ${T.border}`,
                  }}
                >
                  <span style={{ fontSize: 12, color: T.textMuted }}>{k}</span>
                  <span
                    style={{
                      fontSize: 12,
                      fontWeight: 600,
                      color: T.text,
                      textAlign: "right",
                      maxWidth: "55%",
                    }}
                  >
                    {v}
                  </span>
                </div>
              ))}
            </div>
          </div>
          <div>
            {latest && (
              <div
                style={{
                  background: T.card,
                  borderRadius: 16,
                  border: `1px solid ${T.border}`,
                  padding: 20,
                  marginBottom: 16,
                }}
              >
                <div
                  style={{
                    fontWeight: 700,
                    fontSize: 14,
                    color: T.text,
                    marginBottom: 14,
                  }}
                >
                  Latest WHO Z-Scores — {latest.measurement_date}
                </div>
                <div
                  style={{
                    display: "grid",
                    gridTemplateColumns: "repeat(3,1fr)",
                    gap: 12,
                  }}
                >
                  {[
                    ["WAZ", latest.waz, T.accent, "Weight-for-Age"],
                    ["HAZ", latest.haz, T.info, "Height-for-Age"],
                    ["WHZ", latest.whz, T.primaryMid, "Weight-for-Height"],
                  ].map(([l, v, c, d]) => (
                    <div
                      key={l}
                      style={{
                        background: T.bg,
                        borderRadius: 12,
                        padding: 16,
                        textAlign: "center",
                      }}
                    >
                      <div
                        style={{
                          fontSize: 10,
                          color: T.textMuted,
                          letterSpacing: 0.5,
                        }}
                      >
                        {d}
                      </div>
                      <div
                        style={{
                          fontSize: 28,
                          fontWeight: 800,
                          color: Math.abs(v) > 2 ? T.danger : c,
                          margin: "8px 0 4px",
                        }}
                      >
                        {v > 0 ? "+" : ""}
                        {v}
                      </div>
                      <div style={{ fontSize: 10, color: T.textMuted }}>
                        {l} Z-Score
                      </div>
                    </div>
                  ))}
                </div>
                <div
                  style={{
                    marginTop: 12,
                    background: sBg(latest.nutritional_status),
                    border: `1px solid ${sColor(latest.nutritional_status)}30`,
                    borderRadius: 10,
                    padding: "10px 14px",
                    display: "flex",
                    justifyContent: "space-between",
                    alignItems: "center",
                  }}
                >
                  <span
                    style={{
                      fontSize: 12,
                      color: sColor(latest.nutritional_status),
                      fontWeight: 700,
                    }}
                  >
                    Nutritional Status: {latest.nutritional_status}
                  </span>
                  <span style={{ fontSize: 11, color: T.textMuted }}>
                    H: {latest.height_cm}cm · W: {latest.weight_kg}kg
                  </span>
                </div>
              </div>
            )}
            <div
              style={{
                background: T.card,
                borderRadius: 16,
                border: `1px solid ${T.border}`,
                padding: 20,
              }}
            >
              <div
                style={{
                  fontWeight: 700,
                  fontSize: 14,
                  color: T.text,
                  marginBottom: 14,
                }}
              >
                Measurement History
              </div>
              {childMeasurements.length === 0 ? (
                <div
                  style={{
                    textAlign: "center",
                    color: T.textMuted,
                    fontSize: 13,
                    padding: 20,
                  }}
                >
                  No measurements recorded
                </div>
              ) : (
                <table style={{ width: "100%", borderCollapse: "collapse" }}>
                  <thead>
                    <tr>
                      {[
                        "Date",
                        "Height",
                        "Weight",
                        "WAZ",
                        "HAZ",
                        "WHZ",
                        "Status",
                      ].map((h) => (
                        <th
                          key={h}
                          style={{
                            textAlign: "left",
                            padding: "6px 10px",
                            fontSize: 11,
                            color: T.textMuted,
                            borderBottom: `1px solid ${T.border}`,
                          }}
                        >
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {childMeasurements.map((m) => (
                      <tr
                        key={m.id}
                        style={{ borderBottom: `1px solid ${T.border}` }}
                      >
                        <td style={{ padding: "8px 10px", fontSize: 12 }}>
                          {m.measurement_date}
                        </td>
                        <td
                          style={{
                            padding: "8px 10px",
                            fontSize: 12,
                            color: T.textMuted,
                          }}
                        >
                          {m.height_cm}
                        </td>
                        <td
                          style={{
                            padding: "8px 10px",
                            fontSize: 12,
                            color: T.textMuted,
                          }}
                        >
                          {m.weight_kg}
                        </td>
                        <td
                          style={{
                            padding: "8px 10px",
                            fontSize: 12,
                            color: T.accent,
                            fontWeight: 600,
                          }}
                        >
                          {m.waz > 0 ? "+" : ""}
                          {m.waz}
                        </td>
                        <td
                          style={{
                            padding: "8px 10px",
                            fontSize: 12,
                            color: T.info,
                            fontWeight: 600,
                          }}
                        >
                          {m.haz > 0 ? "+" : ""}
                          {m.haz}
                        </td>
                        <td
                          style={{
                            padding: "8px 10px",
                            fontSize: 12,
                            color: T.primaryMid,
                            fontWeight: 600,
                          }}
                        >
                          {m.whz > 0 ? "+" : ""}
                          {m.whz}
                        </td>
                        <td style={{ padding: "8px 10px" }}>
                          <StatusBadge status={m.nutritional_status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Children & Growth"
        subtitle={`${children.length} registered children`}
        action={
          <button
            onClick={() => {
              setForm({
                first_name: "",
                last_name: "",
                birthdate: "",
                sex: "Male",
                barangay: "",
                address: "",
                parent: "",
                age_months: "",
                status: "Normal",
              });
              setModal("add");
            }}
            style={btnPrimary}
          >
            <Icon name="plus" size={14} color="#fff" />
            Add Child
          </button>
        }
      />
      {modal && (
        <Modal title="Add New Child" onClose={() => setModal(null)}>
          <div style={{ padding: 24 }}>
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "1fr 1fr",
                gap: 14,
              }}
            >
              <Field label="First Name" required>
                <input
                  style={inputS}
                  value={form.first_name}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, first_name: e.target.value }))
                  }
                />
              </Field>
              <Field label="Last Name" required>
                <input
                  style={inputS}
                  value={form.last_name}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, last_name: e.target.value }))
                  }
                />
              </Field>
              <Field label="Birthdate" required>
                <input
                  type="date"
                  style={inputS}
                  value={form.birthdate}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, birthdate: e.target.value }))
                  }
                />
              </Field>
              <Field label="Age (months)">
                <input
                  type="number"
                  style={inputS}
                  value={form.age_months}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, age_months: e.target.value }))
                  }
                />
              </Field>
              <Field label="Sex">
                <select
                  style={selectS}
                  value={form.sex}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, sex: e.target.value }))
                  }
                >
                  <option>Male</option>
                  <option>Female</option>
                </select>
              </Field>
              <Field label="Barangay">
                <input
                  style={inputS}
                  value={form.barangay}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, barangay: e.target.value }))
                  }
                />
              </Field>
              <Field label="Parent/Guardian" required>
                <select
                  style={selectS}
                  value={form.parent}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, parent: e.target.value }))
                  }
                >
                  <option value="">-- Select Parent --</option>
                  {parents.map((p) => (
                    <option key={p.id} value={p.name}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Initial Status">
                <select
                  style={selectS}
                  value={form.status}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, status: e.target.value }))
                  }
                >
                  {[
                    "Normal",
                    "Underweight",
                    "Severely Underweight",
                    "Stunted",
                    "Wasted",
                    "Overweight",
                  ].map((s) => (
                    <option key={s}>{s}</option>
                  ))}
                </select>
              </Field>
            </div>
            <Field label="Address">
              <input
                style={inputS}
                value={form.address}
                onChange={(e) =>
                  setForm((p) => ({ ...p, address: e.target.value }))
                }
              />
            </Field>
            <div
              style={{
                display: "flex",
                justifyContent: "flex-end",
                gap: 10,
                marginTop: 8,
              }}
            >
              <button onClick={() => setModal(null)} style={btnSecondary}>
                Cancel
              </button>
              <button
                onClick={() => {
                  if (!form.first_name || !form.last_name) return;
                  showToast(`${form.first_name} ${form.last_name} added`);
                  setModal(null);
                }}
                style={{ ...btnPrimary, padding: "9px 20px" }}
              >
                Add Child
              </button>
            </div>
          </div>
        </Modal>
      )}
      {confirm && (
        <ConfirmDialog
          msg={`Delete ${confirm.first_name} ${confirm.last_name}? This cannot be undone.`}
          onConfirm={() => {
            showToast(`${confirm.first_name} deleted`, "danger");
            setConfirm(null);
          }}
          onCancel={() => setConfirm(null)}
        />
      )}
      <div
        style={{ display: "flex", gap: 10, marginBottom: 16, flexWrap: "wrap" }}
      >
        <input
          placeholder="Search children..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          style={{
            flex: 1,
            minWidth: 200,
            padding: "9px 14px",
            border: `1px solid ${T.border}`,
            borderRadius: 10,
            fontSize: 13,
            outline: "none",
            background: T.card,
          }}
        />
        <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
          {statuses.map((s) => (
            <button
              key={s}
              onClick={() => setFilter(s)}
              style={{
                padding: "6px 12px",
                borderRadius: 20,
                fontSize: 11,
                fontWeight: 600,
                cursor: "pointer",
                border: `1px solid ${filter === s ? sColor(s) : T.border}`,
                background: filter === s ? sBg(s) : T.card,
                color: filter === s ? sColor(s) : T.textMuted,
              }}
            >
              {s}
            </button>
          ))}
        </div>
      </div>
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          border: `1px solid ${T.border}`,
          overflow: "hidden",
        }}
      >
        <table style={{ width: "100%", borderCollapse: "collapse" }}>
          <thead>
            <tr style={{ background: T.bg }}>
              {[
                "Code",
                "Name",
                "Age",
                "Sex",
                "Barangay",
                "Parent",
                "Status",
                "Actions",
              ].map((h) => (
                <th
                  key={h}
                  style={{
                    textAlign: "left",
                    padding: "10px 14px",
                    fontSize: 11,
                    color: T.textMuted,
                    fontWeight: 600,
                    borderBottom: `1px solid ${T.border}`,
                  }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {filtered.map((c, i) => (
              <tr
                key={c.id}
                style={{
                  borderBottom:
                    i < filtered.length - 1 ? `1px solid ${T.border}` : "none",
                }}
              >
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                    fontFamily: "monospace",
                  }}
                >
                  {c.child_code}
                </td>
                <td style={{ padding: "12px 14px" }}>
                  <div
                    style={{ display: "flex", alignItems: "center", gap: 8 }}
                  >
                    <ChildAvatar sex={c.sex} size={30} />
                    <div>
                      <div
                        style={{ fontSize: 13, fontWeight: 600, color: T.text }}
                      >
                        {c.first_name} {c.last_name}
                      </div>
                      <div style={{ fontSize: 10, color: T.textMuted }}>
                        {c.birthdate}
                      </div>
                    </div>
                  </div>
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    color: T.textMuted,
                  }}
                >
                  {c.age_months} mo
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    color: T.textMuted,
                  }}
                >
                  {c.sex}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {c.barangay}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {c.parent}
                </td>
                <td style={{ padding: "12px 14px" }}>
                  <StatusBadge status={c.status} />
                </td>
                <td style={{ padding: "12px 14px" }}>
                  <div style={{ display: "flex", gap: 6 }}>
                    <button
                      onClick={() => setViewChild(c)}
                      style={{
                        background: T.primaryLight,
                        color: T.primary,
                        border: "none",
                        borderRadius: 7,
                        padding: "5px 10px",
                        fontSize: 11,
                        fontWeight: 600,
                        cursor: "pointer",
                      }}
                    >
                      View
                    </button>
                    <button
                      onClick={() => setConfirm(c)}
                      style={{
                        background: T.dangerLight,
                        color: T.danger,
                        border: "none",
                        borderRadius: 7,
                        padding: "5px 8px",
                        cursor: "pointer",
                      }}
                    >
                      <Icon name="trash" size={12} color={T.danger} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function MeasurementsPage({ measurements, children, showToast }) {
  const [modal, setModal] = useState(false);
  const [form, setForm] = useState({
    child_id: "",
    height_cm: "",
    weight_kg: "",
    measurement_date: new Date().toISOString().split("T")[0],
    source_type: "manual",
  });
  const preview = useMemo(() => {
    if (!form.child_id || !form.height_cm || !form.weight_kg) return null;
    const child = children.find((c) => c.id === parseInt(form.child_id));
    if (!child) return null;
    return computeWHO({
      weight_kg: parseFloat(form.weight_kg),
      height_cm: parseFloat(form.height_cm),
      age_months: child.age_months,
    });
  }, [children, form.child_id, form.height_cm, form.weight_kg]);

  return (
    <div>
      <PageHeader
        title="Anthropometric Records"
        subtitle={`${measurements.length} measurements recorded`}
        action={
          <button onClick={() => setModal(true)} style={btnPrimary}>
            <Icon name="plus" size={14} color="#fff" />
            Add Record
          </button>
        }
      />
      {modal && (
        <Modal
          title="Add Measurement Record"
          onClose={() => setModal(false)}
          width={520}
        >
          <div style={{ padding: 24 }}>
            <Field label="Child" required>
              <select
                style={selectS}
                value={form.child_id}
                onChange={(e) =>
                  setForm((p) => ({ ...p, child_id: e.target.value }))
                }
              >
                <option value="">-- Select Child --</option>
                {children.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.first_name} {c.last_name} ({c.age_months} mo)
                  </option>
                ))}
              </select>
            </Field>
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "1fr 1fr",
                gap: 14,
              }}
            >
              <Field label="Height (cm)" required>
                <input
                  type="number"
                  step="0.1"
                  style={inputS}
                  value={form.height_cm}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, height_cm: e.target.value }))
                  }
                  placeholder="e.g. 82.5"
                />
              </Field>
              <Field label="Weight (kg)" required>
                <input
                  type="number"
                  step="0.1"
                  style={inputS}
                  value={form.weight_kg}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, weight_kg: e.target.value }))
                  }
                  placeholder="e.g. 10.8"
                />
              </Field>
              <Field label="Date">
                <input
                  type="date"
                  style={inputS}
                  value={form.measurement_date}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, measurement_date: e.target.value }))
                  }
                />
              </Field>
              <Field label="Source">
                <select
                  style={selectS}
                  value={form.source_type}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, source_type: e.target.value }))
                  }
                >
                  <option value="kiosk">Kiosk</option>
                  <option value="mobile">Mobile</option>
                  <option value="manual">Manual</option>
                </select>
              </Field>
            </div>
            {preview && (
              <div
                style={{
                  background: sBg(preview.status),
                  border: `1px solid ${sColor(preview.status)}30`,
                  borderRadius: 10,
                  padding: "10px 14px",
                  display: "flex",
                  gap: 16,
                  alignItems: "center",
                }}
              >
                <StatusBadge status={preview.status} />
                <span style={{ fontSize: 12, color: T.textMuted }}>
                  WAZ: {preview.waz > 0 ? "+" : ""}
                  {preview.waz} · HAZ: {preview.haz > 0 ? "+" : ""}
                  {preview.haz} · WHZ: {preview.whz > 0 ? "+" : ""}
                  {preview.whz}
                </span>
              </div>
            )}
            <div
              style={{
                display: "flex",
                justifyContent: "flex-end",
                gap: 10,
                marginTop: 14,
              }}
            >
              <button onClick={() => setModal(false)} style={btnSecondary}>
                Cancel
              </button>
              <button
                onClick={() => {
                  if (!form.child_id || !form.height_cm || !form.weight_kg)
                    return;
                  showToast("Measurement added");
                  setModal(false);
                }}
                style={{ ...btnPrimary, padding: "9px 20px" }}
              >
                Add Record
              </button>
            </div>
          </div>
        </Modal>
      )}
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          border: `1px solid ${T.border}`,
          overflow: "auto",
        }}
      >
        <table
          style={{ width: "100%", borderCollapse: "collapse", minWidth: 900 }}
        >
          <thead>
            <tr style={{ background: T.bg }}>
              {[
                "#",
                "Child",
                "Height",
                "Weight",
                "Age",
                "WAZ",
                "HAZ",
                "WHZ",
                "Date",
                "Source",
                "Status",
                "",
              ].map((h) => (
                <th
                  key={h}
                  style={{
                    textAlign: "left",
                    padding: "10px 14px",
                    fontSize: 11,
                    color: T.textMuted,
                    fontWeight: 600,
                    borderBottom: `1px solid ${T.border}`,
                    whiteSpace: "nowrap",
                  }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {measurements.map((m, i) => (
              <tr
                key={m.id}
                style={{
                  borderBottom:
                    i < measurements.length - 1
                      ? `1px solid ${T.border}`
                      : "none",
                }}
              >
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {m.id}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    fontWeight: 600,
                    color: T.text,
                  }}
                >
                  {m.child}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    color: T.textMuted,
                  }}
                >
                  {m.height_cm}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    color: T.textMuted,
                  }}
                >
                  {m.weight_kg}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    color: T.textMuted,
                  }}
                >
                  {m.age_months}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    fontWeight: 600,
                    color: m.waz < -2 ? T.danger : T.primary,
                  }}
                >
                  {m.waz > 0 ? "+" : ""}
                  {m.waz}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    fontWeight: 600,
                    color: m.haz < -2 ? T.danger : T.info,
                  }}
                >
                  {m.haz > 0 ? "+" : ""}
                  {m.haz}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 13,
                    fontWeight: 600,
                    color: m.whz < -2 ? T.danger : T.primaryMid,
                  }}
                >
                  {m.whz > 0 ? "+" : ""}
                  {m.whz}
                </td>
                <td
                  style={{
                    padding: "12px 14px",
                    fontSize: 12,
                    color: T.textMuted,
                    whiteSpace: "nowrap",
                  }}
                >
                  {m.measurement_date}
                </td>
                <td style={{ padding: "12px 14px" }}>
                  <span
                    style={{
                      background: T.bg,
                      padding: "2px 8px",
                      borderRadius: 6,
                      fontSize: 11,
                      color: T.textMuted,
                    }}
                  >
                    {m.source_type}
                  </span>
                </td>
                <td style={{ padding: "12px 14px" }}>
                  <StatusBadge status={m.nutritional_status} />
                </td>
                <td style={{ padding: "12px 14px" }}>
                  <button
                    onClick={() => showToast("Record deleted", "danger")}
                    style={{
                      background: T.dangerLight,
                      color: T.danger,
                      border: "none",
                      borderRadius: 7,
                      padding: "5px 8px",
                      cursor: "pointer",
                    }}
                  >
                    <Icon name="trash" size={12} color={T.danger} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function WHOAnalysis({ measurements }) {
  const avgWaz = (
    measurements.reduce((s, m) => s + m.waz, 0) / measurements.length
  ).toFixed(2);
  const avgHaz = (
    measurements.reduce((s, m) => s + m.haz, 0) / measurements.length
  ).toFixed(2);
  const avgWhz = (
    measurements.reduce((s, m) => s + m.whz, 0) / measurements.length
  ).toFixed(2);

  return (
    <div>
      <PageHeader
        title="WHO Growth Analysis"
        subtitle="Z-score analysis using WHO Child Growth Standards (2006)"
      />
      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(3,1fr)",
          gap: 14,
          marginBottom: 20,
        }}
      >
        {[
          ["Weight-for-Age (WAZ)", avgWaz, T.accent, "Population average"],
          ["Height-for-Age (HAZ)", avgHaz, T.info, "Population average"],
          [
            "Weight-for-Height (WHZ)",
            avgWhz,
            T.primaryMid,
            "Population average",
          ],
        ].map(([label, val, color, desc]) => (
          <div
            key={label}
            style={{
              background: T.card,
              borderRadius: 14,
              border: `1px solid ${T.border}`,
              padding: 20,
              textAlign: "center",
            }}
          >
            <div style={{ fontSize: 12, color: T.textMuted, marginBottom: 8 }}>
              {label}
            </div>
            <div
              style={{
                fontSize: 36,
                fontWeight: 800,
                color: Math.abs(parseFloat(val)) > 2 ? T.danger : color,
              }}
            >
              {parseFloat(val) > 0 ? "+" : ""}
              {val}
            </div>
            <div style={{ fontSize: 11, color: T.textMuted, marginTop: 4 }}>
              {desc}
            </div>
            <div
              style={{
                marginTop: 10,
                padding: "6px 12px",
                borderRadius: 20,
                display: "inline-block",
                background:
                  Math.abs(parseFloat(val)) > 2
                    ? T.dangerLight
                    : T.primaryLight,
                color: Math.abs(parseFloat(val)) > 2 ? T.danger : T.primary,
                fontSize: 11,
                fontWeight: 600,
              }}
            >
              {Math.abs(parseFloat(val)) > 2
                ? "Below Normal"
                : Math.abs(parseFloat(val)) < 1
                  ? "Within Range"
                  : "Borderline"}
            </div>
          </div>
        ))}
      </div>
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          border: `1px solid ${T.border}`,
          padding: 24,
          marginBottom: 20,
        }}
      >
        <div
          style={{
            fontWeight: 700,
            fontSize: 15,
            color: T.text,
            marginBottom: 16,
          }}
        >
          WHO Z-Score Interpretation Guide
        </div>
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(2,1fr)",
            gap: 10,
          }}
        >
          {[
            [
              "Z < -3",
              "Severely Underweight / Severely Wasted / Severely Stunted",
              T.danger,
            ],
            ["-3 ≤ Z < -2", "Underweight / Wasted / Stunted", T.warn],
            ["-2 ≤ Z < -1", "Mild Risk (monitor closely)", T.accent],
            ["-1 ≤ Z ≤ +1", "Normal range — healthy growth", T.primary],
            ["+1 < Z ≤ +2", "Above average (acceptable)", T.info],
            ["Z > +2", "Overweight / Obese", T.purple],
          ].map(([range, label, color]) => (
            <div
              key={range}
              style={{
                display: "flex",
                alignItems: "flex-start",
                gap: 12,
                padding: "12px 14px",
                background: color + "0D",
                borderRadius: 10,
                border: `1px solid ${color}25`,
              }}
            >
              <span
                style={{
                  background: color,
                  color: "#fff",
                  fontSize: 10,
                  fontWeight: 700,
                  padding: "2px 8px",
                  borderRadius: 20,
                  whiteSpace: "nowrap",
                  flexShrink: 0,
                }}
              >
                {range}
              </span>
              <span style={{ fontSize: 12, color: T.text }}>{label}</span>
            </div>
          ))}
        </div>
      </div>
      <div
        style={{
          background: T.card,
          borderRadius: 16,
          border: `1px solid ${T.border}`,
          padding: 24,
        }}
      >
        <div
          style={{
            fontWeight: 700,
            fontSize: 15,
            color: T.text,
            marginBottom: 16,
          }}
        >
          Individual Z-Score Records
        </div>
        <table style={{ width: "100%", borderCollapse: "collapse" }}>
          <thead>
            <tr style={{ background: T.bg }}>
              {[
                "Child",
                "Age",
                "Height",
                "Weight",
                "WAZ",
                "HAZ",
                "WHZ",
                "Nutritional Status",
                "Date",
              ].map((h) => (
                <th
                  key={h}
                  style={{
                    textAlign: "left",
                    padding: "10px 12px",
                    fontSize: 11,
                    color: T.textMuted,
                    fontWeight: 600,
                    borderBottom: `1px solid ${T.border}`,
                  }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {measurements.map((m, i) => (
              <tr
                key={m.id}
                style={{
                  borderBottom:
                    i < measurements.length - 1
                      ? `1px solid ${T.border}`
                      : "none",
                }}
              >
                <td
                  style={{
                    padding: "10px 12px",
                    fontSize: 13,
                    fontWeight: 600,
                    color: T.text,
                  }}
                >
                  {m.child}
                </td>
                <td
                  style={{
                    padding: "10px 12px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {m.age_months} mo
                </td>
                <td
                  style={{
                    padding: "10px 12px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {m.height_cm} cm
                </td>
                <td
                  style={{
                    padding: "10px 12px",
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  {m.weight_kg} kg
                </td>
                {[
                  ["waz", T.accent],
                  ["haz", T.info],
                  ["whz", T.primaryMid],
                ].map(([key, color]) => (
                  <td
                    key={key}
                    style={{
                      padding: "10px 12px",
                      fontSize: 13,
                      fontWeight: 700,
                      color: Math.abs(m[key]) > 2 ? T.danger : color,
                    }}
                  >
                    {m[key] > 0 ? "+" : ""}
                    {m[key]}
                  </td>
                ))}
                <td style={{ padding: "10px 12px" }}>
                  <StatusBadge status={m.nutritional_status} />
                </td>
                <td
                  style={{
                    padding: "10px 12px",
                    fontSize: 11,
                    color: T.textMuted,
                  }}
                >
                  {m.measurement_date}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ParentsPage({ parents, children, showToast }) {
  const [modal, setModal] = useState(null);
  const [form, setForm] = useState({
    name: "",
    email: "",
    phone: "",
    status: "Active",
  });
  const [confirm, setConfirm] = useState(null);

  const childrenByParent = children.reduce((acc, c) => {
    if (!acc[c.parent]) acc[c.parent] = [];
    acc[c.parent].push(c);
    return acc;
  }, {});

  return (
    <div>
      <PageHeader
        title="Parent Accounts"
        subtitle={`${parents.length} registered guardians`}
        action={
          <button
            onClick={() => {
              setForm({ name: "", email: "", phone: "", status: "Active" });
              setModal("add");
            }}
            style={btnPrimary}
          >
            <Icon name="plus" size={14} color="#fff" />
            Add Parent
          </button>
        }
      />
      {modal && (
        <Modal
          title={modal === "add" ? "Add Parent" : "Edit Parent"}
          onClose={() => setModal(null)}
          width={440}
        >
          <div style={{ padding: 24 }}>
            <Field label="Full Name" required>
              <input
                style={inputS}
                value={form.name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, name: e.target.value }))
                }
              />
            </Field>
            <Field label="Email Address" required>
              <input
                type="email"
                style={inputS}
                value={form.email}
                onChange={(e) =>
                  setForm((p) => ({ ...p, email: e.target.value }))
                }
              />
            </Field>
            <Field label="Phone Number" required>
              <input
                style={inputS}
                value={form.phone}
                onChange={(e) =>
                  setForm((p) => ({ ...p, phone: e.target.value }))
                }
              />
            </Field>
            <Field label="Status">
              <select
                style={selectS}
                value={form.status}
                onChange={(e) =>
                  setForm((p) => ({ ...p, status: e.target.value }))
                }
              >
                <option>Active</option>
                <option>Inactive</option>
              </select>
            </Field>
            <div
              style={{
                display: "flex",
                justifyContent: "flex-end",
                gap: 10,
                marginTop: 8,
              }}
            >
              <button onClick={() => setModal(null)} style={btnSecondary}>
                Cancel
              </button>
              <button
                onClick={() => {
                  if (!form.name || !form.email) return;
                  showToast(`${form.name} saved`);
                  setModal(null);
                }}
                style={{ ...btnPrimary, padding: "9px 20px" }}
              >
                Save
              </button>
            </div>
          </div>
        </Modal>
      )}
      {confirm && (
        <ConfirmDialog
          msg={`Remove ${confirm.name}?`}
          onConfirm={() => {
            showToast(`${confirm.name} removed`, "danger");
            setConfirm(null);
          }}
          onCancel={() => setConfirm(null)}
        />
      )}
      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(auto-fill,minmax(280px,1fr))",
          gap: 14,
        }}
      >
        {parents.map((p) => {
          const pChildren = childrenByParent[p.name] || [];
          return (
            <div
              key={p.id}
              style={{
                background: T.card,
                borderRadius: 14,
                border: `1px solid ${T.border}`,
                padding: 20,
              }}
            >
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 12,
                  marginBottom: 14,
                }}
              >
                <div
                  style={{
                    width: 42,
                    height: 42,
                    borderRadius: "50%",
                    background: T.primaryLight,
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    fontWeight: 700,
                    fontSize: 16,
                    color: T.primary,
                  }}
                >
                  {p.name
                    .split(" ")
                    .map((n) => n[0])
                    .join("")
                    .slice(0, 2)}
                </div>
                <div>
                  <div style={{ fontWeight: 700, fontSize: 14, color: T.text }}>
                    {p.name}
                  </div>
                  <span
                    style={{
                      fontSize: 10,
                      padding: "2px 8px",
                      borderRadius: 10,
                      background: p.status === "Active" ? T.primaryLight : T.bg,
                      color: p.status === "Active" ? T.primary : T.textMuted,
                      fontWeight: 600,
                    }}
                  >
                    {p.status}
                  </span>
                </div>
              </div>
              <div
                style={{
                  borderTop: `1px solid ${T.border}`,
                  paddingTop: 12,
                  display: "flex",
                  flexDirection: "column",
                  gap: 6,
                }}
              >
                {/* Email row — SVG icon */}
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 7,
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  <Icon name="mail" size={13} color={T.textLight} />
                  <span
                    style={{
                      overflow: "hidden",
                      textOverflow: "ellipsis",
                      whiteSpace: "nowrap",
                    }}
                  >
                    {p.email}
                  </span>
                </div>
                {/* Phone row — SVG icon */}
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 7,
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  <Icon name="phone" size={13} color={T.textLight} />
                  {p.phone}
                </div>
                {/* Children count — SVG icon */}
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 7,
                    fontSize: 12,
                    color: T.textMuted,
                  }}
                >
                  <Icon name="children" size={13} color={T.textLight} />
                  {pChildren.length} child{pChildren.length !== 1 ? "ren" : ""}
                </div>
                {pChildren.length > 0 && (
                  <div
                    style={{
                      display: "flex",
                      gap: 4,
                      flexWrap: "wrap",
                      marginTop: 4,
                    }}
                  >
                    {pChildren.map((c) => (
                      <StatusBadge key={c.id} status={c.status} />
                    ))}
                  </div>
                )}
              </div>
              <div style={{ display: "flex", gap: 6, marginTop: 14 }}>
                <button
                  onClick={() => {
                    setForm({ ...p });
                    setModal("edit");
                  }}
                  style={{
                    flex: 1,
                    background: T.infoLight,
                    color: T.info,
                    border: "none",
                    borderRadius: 8,
                    padding: "8px 0",
                    fontSize: 12,
                    fontWeight: 600,
                    cursor: "pointer",
                  }}
                >
                  Edit
                </button>
                <button
                  onClick={() => setConfirm(p)}
                  style={{
                    background: T.dangerLight,
                    color: T.danger,
                    border: "none",
                    borderRadius: 8,
                    padding: "8px 10px",
                    cursor: "pointer",
                  }}
                >
                  <Icon name="trash" size={13} color={T.danger} />
                </button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function AppointmentsPage({ appointments, children, parents, showToast }) {
  const [modal, setModal] = useState(false);
  const [form, setForm] = useState({
    child: "",
    parent: "",
    date: "",
    time: "",
    type: "Follow-up",
    note: "",
    status: "Scheduled",
  });
  const upcoming = appointments.filter((a) => a.status === "Scheduled");
  const completed = appointments.filter((a) => a.status === "Completed");

  return (
    <div>
      <PageHeader
        title="Appointments"
        subtitle={`${upcoming.length} upcoming · ${completed.length} completed`}
        action={
          <button onClick={() => setModal(true)} style={btnPrimary}>
            <Icon name="plus" size={14} color="#fff" />
            New Appointment
          </button>
        }
      />
      {modal && (
        <Modal
          title="New Appointment"
          onClose={() => setModal(false)}
          width={480}
        >
          <div style={{ padding: 24 }}>
            <Field label="Child">
              <select
                style={selectS}
                value={form.child}
                onChange={(e) =>
                  setForm((p) => ({ ...p, child: e.target.value }))
                }
              >
                <option value="">-- Select Child --</option>
                {children.map((c) => (
                  <option key={c.id}>
                    {c.first_name} {c.last_name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Parent/Guardian">
              <select
                style={selectS}
                value={form.parent}
                onChange={(e) =>
                  setForm((p) => ({ ...p, parent: e.target.value }))
                }
              >
                <option value="">-- Select Parent --</option>
                {parents.map((pa) => (
                  <option key={pa.id}>{pa.name}</option>
                ))}
              </select>
            </Field>
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "1fr 1fr",
                gap: 14,
              }}
            >
              <Field label="Date">
                <input
                  type="date"
                  style={inputS}
                  value={form.date}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, date: e.target.value }))
                  }
                />
              </Field>
              <Field label="Time">
                <input
                  type="time"
                  style={inputS}
                  value={form.time}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, time: e.target.value }))
                  }
                />
              </Field>
            </div>
            <Field label="Type">
              <select
                style={selectS}
                value={form.type}
                onChange={(e) =>
                  setForm((p) => ({ ...p, type: e.target.value }))
                }
              >
                {[
                  "Follow-up",
                  "Nutrition Counseling",
                  "Emergency",
                  "Initial Assessment",
                ].map((t) => (
                  <option key={t}>{t}</option>
                ))}
              </select>
            </Field>
            <Field label="Note">
              <input
                style={inputS}
                value={form.note}
                onChange={(e) =>
                  setForm((p) => ({ ...p, note: e.target.value }))
                }
                placeholder="Optional note..."
              />
            </Field>
            <div
              style={{
                display: "flex",
                justifyContent: "flex-end",
                gap: 10,
                marginTop: 8,
              }}
            >
              <button onClick={() => setModal(false)} style={btnSecondary}>
                Cancel
              </button>
              <button
                onClick={() => {
                  showToast("Appointment scheduled");
                  setModal(false);
                }}
                style={{ ...btnPrimary, padding: "9px 20px" }}
              >
                Schedule
              </button>
            </div>
          </div>
        </Modal>
      )}
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 20,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 16,
            }}
          >
            Upcoming ({upcoming.length})
          </div>
          {upcoming.map((a) => (
            <div
              key={a.id}
              style={{
                background: T.bg,
                borderRadius: 12,
                padding: 14,
                marginBottom: 10,
                border: `1px solid ${T.border}`,
              }}
            >
              <div
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "flex-start",
                  marginBottom: 6,
                }}
              >
                <div style={{ fontWeight: 700, fontSize: 13, color: T.text }}>
                  {a.child}
                </div>
                <span
                  style={{
                    background: T.primaryLight,
                    color: T.primary,
                    fontSize: 10,
                    fontWeight: 600,
                    padding: "2px 8px",
                    borderRadius: 20,
                  }}
                >
                  {a.type}
                </span>
              </div>
              {/* SVG icons for profile, schedule, and notes */}
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 6,
                  fontSize: 12,
                  color: T.textMuted,
                }}
              >
                <Icon name="user" size={12} color={T.textLight} />
                {a.parent}
              </div>
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 6,
                  fontSize: 12,
                  color: T.textMuted,
                  marginTop: 3,
                }}
              >
                <Icon name="calendar" size={12} color={T.textLight} />
                {a.date} at {a.time}
              </div>
              {a.note && (
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 6,
                    fontSize: 11,
                    color: T.textLight,
                    marginTop: 3,
                  }}
                >
                  <Icon name="edit" size={11} color={T.textLight} />
                  {a.note}
                </div>
              )}
              <div style={{ display: "flex", gap: 6, marginTop: 10 }}>
                <button
                  onClick={() => showToast("Marked as complete")}
                  style={{
                    flex: 1,
                    background: T.primaryLight,
                    color: T.primary,
                    border: "none",
                    borderRadius: 7,
                    padding: "6px 0",
                    fontSize: 11,
                    fontWeight: 600,
                    cursor: "pointer",
                  }}
                >
                  Mark Complete
                </button>
                <button
                  onClick={() => showToast("Appointment cancelled", "warn")}
                  style={{
                    flex: 1,
                    background: T.dangerLight,
                    color: T.danger,
                    border: "none",
                    borderRadius: 7,
                    padding: "6px 0",
                    fontSize: 11,
                    fontWeight: 600,
                    cursor: "pointer",
                  }}
                >
                  Cancel
                </button>
              </div>
            </div>
          ))}
        </div>
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 20,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 16,
            }}
          >
            Completed ({completed.length})
          </div>
          {completed.map((a) => (
            <div
              key={a.id}
              style={{
                background: T.bg,
                borderRadius: 12,
                padding: 14,
                marginBottom: 10,
                border: `1px solid ${T.border}`,
                opacity: 0.8,
              }}
            >
              <div
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "flex-start",
                  marginBottom: 6,
                }}
              >
                <div style={{ fontWeight: 700, fontSize: 13, color: T.text }}>
                  {a.child}
                </div>
                <span
                  style={{
                    background: T.primaryLight,
                    color: T.primary,
                    fontSize: 10,
                    fontWeight: 600,
                    padding: "2px 8px",
                    borderRadius: 20,
                    display: "flex",
                    alignItems: "center",
                    gap: 4,
                  }}
                >
                  <Icon name="check" size={10} color={T.primary} /> Done
                </span>
              </div>
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 6,
                  fontSize: 12,
                  color: T.textMuted,
                }}
              >
                <Icon name="user" size={12} color={T.textLight} />
                {a.parent}
              </div>
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 6,
                  fontSize: 12,
                  color: T.textMuted,
                  marginTop: 3,
                }}
              >
                <Icon name="calendar" size={12} color={T.textLight} />
                {a.date} at {a.time}
              </div>
              {a.note && (
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 6,
                    fontSize: 11,
                    color: T.textLight,
                    marginTop: 3,
                  }}
                >
                  <Icon name="edit" size={11} color={T.textLight} />
                  {a.note}
                </div>
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function ReportsPage({ children }) {
  const statusCounts = children.reduce((acc, c) => {
    acc[c.status] = (acc[c.status] || 0) + 1;
    return acc;
  }, {});
  const atRisk = children.filter((c) => !["Normal"].includes(c.status)).length;
  const normal = children.filter((c) => c.status === "Normal").length;

  return (
    <div>
      <PageHeader
        title="Nutrition Reports"
        subtitle="Monthly growth and malnutrition analytics"
        action={
          <button style={btnPrimary}>
            <Icon name="save" size={14} color="#fff" />
            Export CSV
          </button>
        }
      />
      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(3,1fr)",
          gap: 14,
          marginBottom: 20,
        }}
      >
        {[
          { label: "Total Children", value: children.length, color: T.primary },
          { label: "Normal Growth", value: normal, color: T.primaryMid },
          { label: "At-Risk", value: atRisk, color: T.danger },
        ].map((s) => (
          <div
            key={s.label}
            style={{
              background: T.card,
              borderRadius: 14,
              border: `1px solid ${T.border}`,
              padding: 20,
            }}
          >
            <div style={{ fontSize: 12, color: T.textMuted }}>{s.label}</div>
            <div
              style={{
                fontSize: 36,
                fontWeight: 800,
                color: s.color,
                margin: "8px 0",
              }}
            >
              {s.value}
            </div>
            <div style={{ height: 6, borderRadius: 999, background: T.bg }}>
              <div
                style={{
                  width: `${(s.value / children.length) * 100}%`,
                  height: "100%",
                  borderRadius: 999,
                  background: s.color,
                }}
              />
            </div>
          </div>
        ))}
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 24,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 16,
            }}
          >
            Status Breakdown
          </div>
          {Object.entries(statusCounts).map(([status, count]) => {
            const pct = Math.round((count / children.length) * 100);
            return (
              <div key={status} style={{ marginBottom: 14 }}>
                <div
                  style={{
                    display: "flex",
                    justifyContent: "space-between",
                    marginBottom: 5,
                  }}
                >
                  <StatusBadge status={status} />
                  <span style={{ fontSize: 12, color: T.textMuted }}>
                    {count} children ({pct}%)
                  </span>
                </div>
                <div
                  style={{
                    height: 10,
                    borderRadius: 999,
                    background: T.bg,
                    overflow: "hidden",
                  }}
                >
                  <div
                    style={{
                      width: `${Math.max(4, pct)}%`,
                      height: "100%",
                      borderRadius: 999,
                      background: sColor(status),
                    }}
                  />
                </div>
              </div>
            );
          })}
        </div>
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 24,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 16,
            }}
          >
            Priority Follow-up List
          </div>
          {children
            .filter((c) => c.status !== "Normal")
            .slice(0, 6)
            .map((c) => (
              <div
                key={c.id}
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "center",
                  padding: "10px 0",
                  borderBottom: `1px solid ${T.border}`,
                }}
              >
                <div>
                  <div style={{ fontWeight: 700, fontSize: 13, color: T.text }}>
                    {c.first_name} {c.last_name}
                  </div>
                  <div style={{ fontSize: 11, color: T.textMuted }}>
                    {c.barangay} · {c.age_months} months · Parent: {c.parent}
                  </div>
                </div>
                <StatusBadge status={c.status} />
              </div>
            ))}
        </div>
      </div>
    </div>
  );
}

function NutritionistSettings({ user, showToast }) {
  const [form, setForm] = useState({
    name: user.name,
    email: user.email,
    phone: "09171234560",
    barangay: "Bagong Silang",
    role: "Registered Dietitian Nutritionist (RDN)",
    notify_alerts: true,
    notify_appointments: true,
  });

  return (
    <div>
      <PageHeader
        title="My Settings"
        subtitle="Manage your profile and notification preferences"
      />
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 24,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 20,
            }}
          >
            Profile Information
          </div>
          <Field label="Full Name">
            <input
              style={inputS}
              value={form.name}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
            />
          </Field>
          <Field label="Email Address">
            <input
              type="email"
              style={inputS}
              value={form.email}
              onChange={(e) =>
                setForm((p) => ({ ...p, email: e.target.value }))
              }
            />
          </Field>
          <Field label="Phone Number">
            <input
              style={inputS}
              value={form.phone}
              onChange={(e) =>
                setForm((p) => ({ ...p, phone: e.target.value }))
              }
            />
          </Field>
          <Field label="Assigned Barangay">
            <input
              style={inputS}
              value={form.barangay}
              onChange={(e) =>
                setForm((p) => ({ ...p, barangay: e.target.value }))
              }
            />
          </Field>
          <Field label="Professional Role">
            <input
              style={inputS}
              value={form.role}
              onChange={(e) => setForm((p) => ({ ...p, role: e.target.value }))}
            />
          </Field>
          <button
            onClick={() => showToast("Profile updated")}
            style={btnPrimary}
          >
            <Icon name="save" size={14} color="#fff" />
            Save Profile
          </button>
        </div>
        <div
          style={{
            background: T.card,
            borderRadius: 16,
            border: `1px solid ${T.border}`,
            padding: 24,
          }}
        >
          <div
            style={{
              fontWeight: 700,
              fontSize: 15,
              color: T.text,
              marginBottom: 20,
            }}
          >
            Notification Preferences
          </div>
          {[
            ["notify_alerts", "Priority health alerts (at-risk children)"],
            ["notify_appointments", "Appointment reminders"],
          ].map(([key, label]) => (
            <div
              key={key}
              style={{
                display: "flex",
                alignItems: "center",
                justifyContent: "space-between",
                padding: "14px 0",
                borderBottom: `1px solid ${T.border}`,
              }}
            >
              <div style={{ fontSize: 13, color: T.text }}>{label}</div>
              <button
                onClick={() => setForm((p) => ({ ...p, [key]: !p[key] }))}
                style={{
                  width: 44,
                  height: 24,
                  borderRadius: 999,
                  border: "none",
                  cursor: "pointer",
                  background: form[key] ? T.primaryMid : T.border,
                  position: "relative",
                  transition: "background 0.2s",
                }}
              >
                <div
                  style={{
                    position: "absolute",
                    top: 2,
                    left: form[key] ? "calc(100% - 22px)" : 2,
                    width: 20,
                    height: 20,
                    borderRadius: "50%",
                    background: "#fff",
                    transition: "left 0.2s",
                  }}
                />
              </button>
            </div>
          ))}
          <div
            style={{
              marginTop: 24,
              padding: 16,
              background: T.bg,
              borderRadius: 12,
            }}
          >
            <div
              style={{
                fontSize: 12,
                fontWeight: 600,
                color: T.text,
                marginBottom: 4,
              }}
            >
              Account Info
            </div>
            <div style={{ fontSize: 11, color: T.textMuted }}>
              Role: {user.role}
            </div>
            <div style={{ fontSize: 11, color: T.textMuted }}>
              Email: {user.email}
            </div>
            <div style={{ fontSize: 11, color: T.textMuted }}>
              Last login: Today, 09:15 AM
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
// KIOSK VIEW — standalone, accessible from login screen only
// ═══════════════════════════════════════════════════════════════════════════════
function KioskView({ children, onBack }) {
  const [showWelcome, setShowWelcome] = useState(true);
  // steps: 0=select child, 1=height scan, 2=weight scan, 3=processing, 4=results
  const [step, setStep] = useState(0);
  const [search, setSearch] = useState("");
  const [selectedChild, setSelectedChild] = useState(null);
  const [currentTime, setCurrentTime] = useState(new Date());

  // Live sensor readings
  const [heightReading, setHeightReading] = useState(null);
  const [heightFinal, setHeightFinal] = useState(null);
  const [heightScanState, setHeightScanState] = useState("idle"); // idle|scanning|locking|done

  const [weightReading, setWeightReading] = useState(null);
  const [weightFinal, setWeightFinal] = useState(null);
  const [weightScanState, setWeightScanState] = useState("idle"); // idle|stabilizing|locking|done

  // Pre-generate random values for weight bar visualization
  const [weightBarRandoms] = useState(() =>
    Array.from({ length: 12 }, () => ({
      height: 8 + Math.floor(Math.random() * 16),
      duration: 0.2 + Math.random() * 0.3,
    }))
  );

  // Processing
  const [processStage, setProcessStage] = useState(0);
  const [processProgress, setProcessProgress] = useState(0);
  const [result, setResult] = useState(null);

  const timerRef = useRef(null);
  const heightIntervalRef = useRef(null);
  const weightIntervalRef = useRef(null);

  useEffect(() => {
    const iv = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(iv);
  }, []);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      clearInterval(heightIntervalRef.current);
      clearInterval(weightIntervalRef.current);
      clearInterval(timerRef.current);
    };
  }, []);

  // ── HEIGHT SCAN ──────────────────────────────────────────────────────────────
  const startHeightScan = () => {
    if (!selectedChild) return;
    setHeightScanState("scanning");
    setHeightFinal(null);

    // Base height derived from child age (realistic range)
    const baseHeight =
      55 + selectedChild.age_months * 0.9 + (Math.random() * 6 - 3);

    let ticks = 0;
    heightIntervalRef.current = setInterval(() => {
      ticks++;
      // Fluctuate ±3cm while scanning, narrowing as it stabilizes
      const noise =
        ticks < 30
          ? Math.random() * 6 - 3
          : ticks < 50
            ? Math.random() * 2 - 1
            : Math.random() * 0.4 - 0.2;
      const reading = baseHeight + noise;
      setHeightReading(parseFloat(reading.toFixed(1)));

      if (ticks === 50) {
        setHeightScanState("locking");
      }
      if (ticks >= 65) {
        clearInterval(heightIntervalRef.current);
        const locked = parseFloat(baseHeight.toFixed(1));
        setHeightReading(locked);
        setHeightFinal(locked);
        setHeightScanState("done");
      }
    }, 60);
  };

  const proceedToWeight = () => {
    setStep(2);
    setWeightScanState("idle");
    setWeightReading(null);
    setWeightFinal(null);
  };

  // ── WEIGHT SCAN ──────────────────────────────────────────────────────────────
  const startWeightScan = () => {
    if (!selectedChild) return;
    setWeightScanState("stabilizing");
    setWeightFinal(null);

    // Realistic weight from age
    const baseWeight =
      3.5 + selectedChild.age_months * 0.25 + (Math.random() * 1.4 - 0.7);

    let ticks = 0;
    weightIntervalRef.current = setInterval(() => {
      ticks++;
      const noise =
        ticks < 20
          ? Math.random() * 2 - 1
          : ticks < 45
            ? Math.random() * 0.6 - 0.3
            : Math.random() * 0.08 - 0.04;
      const reading = baseWeight + noise;
      setWeightReading(parseFloat(reading.toFixed(2)));

      if (ticks === 45) {
        setWeightScanState("locking");
      }
      if (ticks >= 58) {
        clearInterval(weightIntervalRef.current);
        const locked = parseFloat(baseWeight.toFixed(2));
        setWeightReading(locked);
        setWeightFinal(locked);
        setWeightScanState("done");
      }
    }, 70);
  };

  const proceedToProcessing = () => {
    setStep(3);
    setProcessStage(0);
    setProcessProgress(0);

    const STAGES = [
      "Validating sensor data...",
      "Applying WHO 2006 standards...",
      "Computing WAZ (Weight-for-Age)...",
      "Computing HAZ (Height-for-Age)...",
      "Computing WHZ (Weight-for-Height)...",
      "Classifying nutritional status...",
      "Syncing to eOPT+ database...",
      "Complete!",
    ];

    let p = 0;
    timerRef.current = setInterval(() => {
      p += 100 / 55;
      const stageIdx = Math.min(
        Math.floor((p / 100) * STAGES.length),
        STAGES.length - 1,
      );
      setProcessProgress(Math.min(p, 100));
      setProcessStage(stageIdx);

      if (p >= 100) {
        clearInterval(timerRef.current);
        const r = computeWHO({
          weight_kg: weightFinal,
          height_cm: heightFinal,
          age_months: selectedChild.age_months,
        });
        setResult(r);
        setTimeout(() => setStep(4), 500);
      }
    }, 90);
  };

  const resetKiosk = () => {
    clearInterval(heightIntervalRef.current);
    clearInterval(weightIntervalRef.current);
    clearInterval(timerRef.current);
    setStep(0);
    setSelectedChild(null);
    setSearch("");
    setHeightReading(null);
    setHeightFinal(null);
    setHeightScanState("idle");
    setWeightReading(null);
    setWeightFinal(null);
    setWeightScanState("idle");
    setProcessProgress(0);
    setProcessStage(0);
    setResult(null);
  };

  const K = {
    bg: "linear-gradient(135deg,#0D2B20 0%,#0B3D2A 50%,#0D2B20 100%)",
    text: "#fff",
    muted: "rgba(255,255,255,0.6)",
    faint: "rgba(255,255,255,0.25)",
    panel: "rgba(255,255,255,0.05)",
    border: "rgba(255,255,255,0.1)",
    accent: "#2BC88A",
    accentDim: "rgba(43,200,138,0.2)",
    danger: "#E03131",
    warn: "#E67E22",
  };

  const STEP_LABELS = [
    "Select Child",
    "Height Scan",
    "Weight Scan",
    "Processing",
    "Results",
  ];

  // ── Processing STAGES list (for display) ────────────────────────────────────
  const PROC_STAGES = [
    "Validating sensor data...",
    "Applying WHO 2006 standards...",
    "Computing WAZ (Weight-for-Age)...",
    "Computing HAZ (Height-for-Age)...",
    "Computing WHZ (Weight-for-Height)...",
    "Classifying nutritional status...",
    "Syncing to eOPT+ database...",
    "Complete!",
  ];

  // ── Step indicator ───────────────────────────────────────────────────────────
  const StepBar = () => (
    <div
      style={{
        display: "flex",
        justifyContent: "center",
        gap: 0,
        padding: "18px 0 4px",
      }}
    >
      {STEP_LABELS.map((label, i) => (
        <div key={label} style={{ display: "flex", alignItems: "center" }}>
          <div
            style={{
              display: "flex",
              flexDirection: "column",
              alignItems: "center",
              gap: 4,
            }}
          >
            <div
              style={{
                width: 28,
                height: 28,
                borderRadius: "50%",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                background:
                  step > i ? K.accent : step === i ? K.accentDim : K.panel,
                border: `2px solid ${step >= i ? K.accent : K.border}`,
                color: step >= i ? K.text : K.faint,
                fontWeight: 700,
                fontSize: 11,
              }}
            >
              {step > i ? <Icon name="check" size={12} color="#fff" /> : i + 1}
            </div>
            <div
              style={{
                color: step === i ? K.accent : K.faint,
                fontSize: 9,
                whiteSpace: "nowrap",
              }}
            >
              {label}
            </div>
          </div>
          {i < STEP_LABELS.length - 1 && (
            <div
              style={{
                width: 48,
                height: 2,
                background: step > i ? K.accent : K.border,
                margin: "0 4px 16px",
              }}
            />
          )}
        </div>
      ))}
    </div>
  );

  // ── Result icon ──────────────────────────────────────────────────────────────
  const ResultIcon = ({ isNormal }) => (
    <svg width={52} height={52} viewBox="0 0 56 56" fill="none">
      <circle
        cx="28"
        cy="28"
        r="26"
        fill={isNormal ? "rgba(43,200,138,0.15)" : "rgba(224,49,49,0.15)"}
        stroke={isNormal ? "#2BC88A" : K.danger}
        strokeWidth="2"
      />
      {isNormal ? (
        <path
          d="M18 28 L24 34 L38 20"
          stroke="#2BC88A"
          strokeWidth="3"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      ) : (
        <path
          d="M20 20 L36 36 M36 20 L20 36"
          stroke={K.danger}
          strokeWidth="3"
          strokeLinecap="round"
        />
      )}
    </svg>
  );

  // ── Shared header ────────────────────────────────────────────────────────────
  const Header = () => (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        padding: "14px 24px",
        borderBottom: `1px solid ${K.border}`,
        position: "sticky",
        top: 0,
        zIndex: 10,
        background: "rgba(13,43,32,0.95)",
        backdropFilter: "blur(8px)",
      }}
    >
      <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
        <div
          style={{
            width: 34,
            height: 34,
            borderRadius: 8,
            background: K.accent,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
          }}
        >
          <Icon name="heart" size={17} color="#fff" />
        </div>
        <div>
          <div style={{ color: K.text, fontWeight: 700, fontSize: 14 }}>
            SukatKalusugan
          </div>
          <div style={{ color: K.muted, fontSize: 9, letterSpacing: 1.5 }}>
            ANTHROPOMETRIC KIOSK v1.0
          </div>
        </div>
      </div>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        {[
          [
            "signal",
            "TF-Luna",
            heightScanState === "done"
              ? K.accent
              : heightScanState === "scanning" || heightScanState === "locking"
                ? K.warn
                : K.muted,
          ],
          [
            "scale",
            "HX711",
            weightScanState === "done"
              ? K.accent
              : weightScanState === "stabilizing" ||
                  weightScanState === "locking"
                ? K.warn
                : K.muted,
          ],
          ["wifi", "WiFi", K.accent],
        ].map(([icon, label, color]) => (
          <span
            key={label}
            style={{
              fontSize: 10,
              color,
              background:
                color === K.accent
                  ? "rgba(43,200,138,0.12)"
                  : "rgba(255,255,255,0.06)",
              padding: "3px 8px",
              borderRadius: 5,
              border: `1px solid ${color === K.accent ? "rgba(43,200,138,0.25)" : "rgba(255,255,255,0.1)"}`,
              display: "flex",
              alignItems: "center",
              gap: 4,
            }}
          >
            <Icon name={icon} size={10} color={color} />
            {label}
          </span>
        ))}
        <button
          onClick={onBack}
          style={{
            background: K.panel,
            color: K.muted,
            border: `1px solid ${K.border}`,
            borderRadius: 7,
            padding: "6px 14px",
            fontSize: 12,
            cursor: "pointer",
            display: "flex",
            alignItems: "center",
            gap: 5,
          }}
        >
          <Icon name="arrowLeft" size={11} color={K.muted} /> Exit
        </button>
      </div>
    </div>
  );

  // ── KioskLogo SVG ────────────────────────────────────────────────────────────
  const KioskLogo = () => (
    <svg width={96} height={96} viewBox="0 0 100 100" fill="none">
      <circle
        cx="50"
        cy="50"
        r="48"
        fill="rgba(43,200,138,0.1)"
        stroke="rgba(43,200,138,0.25)"
        strokeWidth="1"
      />
      <path
        d="M50 68 L22 44 A18 18 0 0 1 50 30 A18 18 0 0 1 78 44 Z"
        fill="none"
        stroke="#2BC88A"
        strokeWidth="2.5"
        strokeLinejoin="round"
      />
      <rect
        x="46"
        y="38"
        width="8"
        height="22"
        rx="2"
        fill="#2BC88A"
        opacity="0.8"
      />
      <rect
        x="39"
        y="45"
        width="22"
        height="8"
        rx="2"
        fill="#2BC88A"
        opacity="0.8"
      />
    </svg>
  );

  // ══════════════════════════════════════════════════════════════════════════════
  // RENDER
  // ══════════════════════════════════════════════════════════════════════════════
  return (
    <div
      style={{
        minHeight: "100vh",
        background: K.bg,
        display: "flex",
        flexDirection: "column",
        fontFamily: "'Inter',ui-sans-serif,system-ui,sans-serif",
      }}
    >
      <Header />

      {!showWelcome && <StepBar />}

      <div
        style={{
          flex: 1,
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          padding: "20px 24px 32px",
        }}
      >
        {/* ══ WELCOME ══════════════════════════════════════════════════════════ */}
        {showWelcome && (
          <div
            style={{
              textAlign: "center",
              display: "flex",
              flexDirection: "column",
              alignItems: "center",
              maxWidth: 480,
            }}
          >
            <div style={{ marginBottom: 24 }}>
              <KioskLogo />
            </div>
            <div style={{ marginBottom: 6 }}>
              <span style={{ fontSize: 38, fontWeight: 300, color: K.muted }}>
                Sukat
              </span>
              <span style={{ fontSize: 38, fontWeight: 700, color: K.accent }}>
                {" "}
                Kalusugan
              </span>
            </div>
            <div
              style={{
                fontSize: 14,
                color: K.muted,
                marginBottom: 24,
                letterSpacing: 0.5,
              }}
            >
              Anthropometric Measurement Kiosk
            </div>
            <div
              style={{
                fontSize: 48,
                fontWeight: 700,
                color: K.text,
                letterSpacing: -1,
                marginBottom: 6,
                fontVariantNumeric: "tabular-nums",
              }}
            >
              {currentTime.toLocaleTimeString("en-PH", {
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit",
                hour12: true,
              })}
            </div>
            <div style={{ fontSize: 12, color: K.muted, marginBottom: 36 }}>
              {currentTime.toLocaleDateString("en-PH", {
                weekday: "long",
                year: "numeric",
                month: "long",
                day: "numeric",
              })}
            </div>
            <div style={{ display: "flex", gap: 10, marginBottom: 36 }}>
              {[
                ["signal", "LiDAR Active"],
                ["scale", "Load Cell OK"],
                ["wifi", "Connected"],
              ].map(([icon, label]) => (
                <div
                  key={label}
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 6,
                    background: K.accentDim,
                    border: "1px solid rgba(43,200,138,0.2)",
                    borderRadius: 8,
                    padding: "6px 12px",
                  }}
                >
                  <Icon name={icon} size={12} color={K.accent} />
                  <span style={{ fontSize: 11, color: K.accent }}>{label}</span>
                </div>
              ))}
            </div>
            <button
              onClick={() => setShowWelcome(false)}
              style={{
                background: K.danger,
                color: "#fff",
                border: "none",
                borderRadius: 14,
                padding: "18px 72px",
                fontSize: 20,
                fontWeight: 700,
                cursor: "pointer",
                letterSpacing: 0.5,
              }}
            >
              Touch to Start
            </button>
          </div>
        )}

        {/* ══ STEP 0: SELECT CHILD ═════════════════════════════════════════════ */}
        {!showWelcome && step === 0 && (
          <div style={{ width: "100%", maxWidth: 720 }}>
            <h2
              style={{
                color: K.text,
                textAlign: "center",
                fontSize: 18,
                fontWeight: 600,
                margin: "0 0 6px",
              }}
            >
              Select Child Profile
            </h2>
            <p
              style={{
                color: K.muted,
                textAlign: "center",
                fontSize: 13,
                margin: "0 0 20px",
              }}
            >
              Search or tap a child to begin measurement
            </p>
            <input
              type="text"
              placeholder="Search by name..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              style={{
                width: "100%",
                background: K.panel,
                border: `1px solid ${K.border}`,
                borderRadius: 10,
                padding: "11px 16px",
                color: K.text,
                fontSize: 14,
                outline: "none",
                boxSizing: "border-box",
                marginBottom: 16,
              }}
            />
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "repeat(auto-fill,minmax(170px,1fr))",
                gap: 10,
              }}
            >
              {children
                .filter(
                  (c) =>
                    search === "" ||
                    `${c.first_name} ${c.last_name}`
                      .toLowerCase()
                      .includes(search.toLowerCase()),
                )
                .slice(0, 9)
                .map((c) => (
                  <div
                    key={c.id}
                    onClick={() => {
                      setSelectedChild(c);
                      setStep(1);
                      setHeightScanState("idle");
                    }}
                    style={{
                      background: K.panel,
                      border: `1px solid ${K.border}`,
                      borderRadius: 12,
                      padding: "14px 12px",
                      cursor: "pointer",
                      transition: "border-color 0.15s, background 0.15s",
                    }}
                    onMouseEnter={(e) => {
                      e.currentTarget.style.borderColor = K.accent;
                      e.currentTarget.style.background = K.accentDim;
                    }}
                    onMouseLeave={(e) => {
                      e.currentTarget.style.borderColor = K.border;
                      e.currentTarget.style.background = K.panel;
                    }}
                  >
                    <div style={{ marginBottom: 8 }}>
                      <ChildAvatar sex={c.sex} size={40} />
                    </div>
                    <div
                      style={{ color: K.text, fontWeight: 600, fontSize: 13 }}
                    >
                      {c.first_name} {c.last_name}
                    </div>
                    <div style={{ color: K.muted, fontSize: 11, marginTop: 2 }}>
                      {c.age_months} months · {c.sex}
                    </div>
                    <div style={{ color: K.faint, fontSize: 10, marginTop: 2 }}>
                      {c.child_code}
                    </div>
                  </div>
                ))}
            </div>
          </div>
        )}

        {/* ══ STEP 1: HEIGHT SCAN ══════════════════════════════════════════════ */}
        {!showWelcome && step === 1 && selectedChild && (
          <div style={{ width: "100%", maxWidth: 520 }}>
            {/* Child info strip */}
            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 12,
                marginBottom: 24,
                background: K.panel,
                borderRadius: 12,
                padding: "12px 16px",
                border: `1px solid ${K.border}`,
              }}
            >
              <ChildAvatar sex={selectedChild.sex} size={44} />
              <div>
                <div style={{ color: K.text, fontWeight: 700, fontSize: 15 }}>
                  {selectedChild.first_name} {selectedChild.last_name}
                </div>
                <div style={{ color: K.muted, fontSize: 12 }}>
                  {selectedChild.child_code} · {selectedChild.age_months} months
                  old
                </div>
              </div>
            </div>

            {/* Sensor panel */}
            <div
              style={{
                background: K.panel,
                borderRadius: 18,
                border: `1px solid ${
                  heightScanState === "done"
                    ? "rgba(43,200,138,0.5)"
                    : heightScanState === "scanning" ||
                        heightScanState === "locking"
                      ? "rgba(230,126,34,0.4)"
                      : K.border
                }`,
                padding: 28,
                textAlign: "center",
                marginBottom: 20,
                transition: "border-color 0.4s",
              }}
            >
              {/* TF-Luna icon + animation */}
              <div
                style={{
                  display: "flex",
                  justifyContent: "center",
                  marginBottom: 16,
                }}
              >
                <div style={{ position: "relative", width: 80, height: 80 }}>
                  {/* Pulse rings when scanning */}
                  {(heightScanState === "scanning" ||
                    heightScanState === "locking") && (
                    <>
                      {[0, 1, 2].map((i) => (
                        <div
                          key={i}
                          style={{
                            position: "absolute",
                            inset: `${-i * 10}px`,
                            borderRadius: "50%",
                            border: `1px solid rgba(230,126,34,${0.5 - i * 0.15})`,
                            animation: `pulseRing 1.2s ease-out ${i * 0.3}s infinite`,
                          }}
                        />
                      ))}
                    </>
                  )}
                  {heightScanState === "done" && (
                    <>
                      {[0, 1].map((i) => (
                        <div
                          key={i}
                          style={{
                            position: "absolute",
                            inset: `${-i * 12}px`,
                            borderRadius: "50%",
                            border: `1px solid rgba(43,200,138,${0.4 - i * 0.15})`,
                            animation: `pulseRing 1.5s ease-out ${i * 0.4}s infinite`,
                          }}
                        />
                      ))}
                    </>
                  )}
                  {/* Sensor icon circle */}
                  <div
                    style={{
                      position: "absolute",
                      inset: 0,
                      borderRadius: "50%",
                      background:
                        heightScanState === "done"
                          ? "rgba(43,200,138,0.2)"
                          : heightScanState !== "idle"
                            ? "rgba(230,126,34,0.2)"
                            : K.accentDim,
                      display: "flex",
                      alignItems: "center",
                      justifyContent: "center",
                      border: `2px solid ${
                        heightScanState === "done"
                          ? K.accent
                          : heightScanState !== "idle"
                            ? K.warn
                            : "rgba(43,200,138,0.3)"
                      }`,
                      transition: "all 0.4s",
                    }}
                  >
                    <Icon
                      name="scan"
                      size={30}
                      color={
                        heightScanState === "done"
                          ? K.accent
                          : heightScanState !== "idle"
                            ? K.warn
                            : K.muted
                      }
                    />
                  </div>
                </div>
              </div>

              {/* Sensor label */}
              <div
                style={{
                  fontSize: 11,
                  color: K.muted,
                  letterSpacing: 1.5,
                  marginBottom: 8,
                }}
              >
                TF-LUNA LiDAR SENSOR
              </div>

              {/* Reading display */}
              <div
                style={{
                  fontSize: 52,
                  fontWeight: 800,
                  color:
                    heightScanState === "done"
                      ? K.accent
                      : heightScanState !== "idle"
                        ? K.warn
                        : K.faint,
                  fontVariantNumeric: "tabular-nums",
                  letterSpacing: -2,
                  marginBottom: 4,
                  minHeight: 64,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  gap: 6,
                  transition: "color 0.3s",
                }}
              >
                {heightReading !== null ? heightReading.toFixed(1) : "—"}
                <span
                  style={{
                    fontSize: 18,
                    fontWeight: 400,
                    color: K.muted,
                    letterSpacing: 0,
                  }}
                >
                  cm
                </span>
              </div>

              {/* Status text */}
              <div
                style={{
                  fontSize: 13,
                  color:
                    heightScanState === "done"
                      ? K.accent
                      : heightScanState === "locking"
                        ? K.warn
                        : heightScanState === "scanning"
                          ? "rgba(230,126,34,0.9)"
                          : K.faint,
                  fontWeight: 600,
                  minHeight: 20,
                }}
              >
                {heightScanState === "idle" && "Ready to measure height"}
                {heightScanState === "scanning" && "Scanning… stand still"}
                {heightScanState === "locking" && "Stabilizing reading…"}
                {heightScanState === "done" &&
                  `✓ Height locked — ${heightFinal} cm`}
              </div>

              {/* Beam animation when scanning */}
              {(heightScanState === "scanning" ||
                heightScanState === "locking") && (
                <div style={{ marginTop: 16 }}>
                  <div
                    style={{
                      height: 3,
                      borderRadius: 999,
                      background: K.border,
                      overflow: "hidden",
                      width: "60%",
                      margin: "0 auto",
                    }}
                  >
                    <div
                      style={{
                        height: "100%",
                        borderRadius: 999,
                        background: K.warn,
                        animation:
                          "scanBeam 0.8s ease-in-out infinite alternate",
                        width: "40%",
                      }}
                    />
                  </div>
                </div>
              )}
            </div>

            {/* Action button */}
            {heightScanState === "idle" && (
              <button
                onClick={startHeightScan}
                style={{
                  width: "100%",
                  background: K.accent,
                  color: "#fff",
                  border: "none",
                  borderRadius: 12,
                  padding: "15px 0",
                  fontSize: 15,
                  fontWeight: 700,
                  cursor: "pointer",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  gap: 8,
                }}
              >
                <Icon name="scan" size={17} color="#fff" />
                START HEIGHT MEASUREMENT
              </button>
            )}

            {(heightScanState === "scanning" ||
              heightScanState === "locking") && (
              <div
                style={{
                  width: "100%",
                  background: "rgba(230,126,34,0.15)",
                  border: "1px solid rgba(230,126,34,0.3)",
                  borderRadius: 12,
                  padding: "14px 0",
                  fontSize: 14,
                  color: K.warn,
                  fontWeight: 600,
                  textAlign: "center",
                }}
              >
                Measuring… please wait
              </div>
            )}

            {heightScanState === "done" && (
              <button
                onClick={proceedToWeight}
                style={{
                  width: "100%",
                  background: K.accent,
                  color: "#fff",
                  border: "none",
                  borderRadius: 12,
                  padding: "15px 0",
                  fontSize: 15,
                  fontWeight: 700,
                  cursor: "pointer",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  gap: 8,
                }}
              >
                Continue to Weight Measurement
                <Icon name="arrowRight" size={16} color="#fff" />
              </button>
            )}

            <style>{`
              @keyframes pulseRing {
                0% { opacity: 1; transform: scale(1); }
                100% { opacity: 0; transform: scale(1.5); }
              }
              @keyframes scanBeam {
                0% { transform: translateX(-100%); }
                100% { transform: translateX(250%); }
              }
              @keyframes weightFlicker {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.7; }
              }
            `}</style>
          </div>
        )}

        {/* ══ STEP 2: WEIGHT SCAN ══════════════════════════════════════════════ */}
        {!showWelcome && step === 2 && selectedChild && (
          <div style={{ width: "100%", maxWidth: 520 }}>
            {/* Child info strip */}
            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 12,
                marginBottom: 24,
                background: K.panel,
                borderRadius: 12,
                padding: "12px 16px",
                border: `1px solid ${K.border}`,
              }}
            >
              <ChildAvatar sex={selectedChild.sex} size={44} />
              <div style={{ flex: 1 }}>
                <div style={{ color: K.text, fontWeight: 700, fontSize: 15 }}>
                  {selectedChild.first_name} {selectedChild.last_name}
                </div>
                <div style={{ color: K.muted, fontSize: 12 }}>
                  {selectedChild.child_code} · {selectedChild.age_months} months
                  old
                </div>
              </div>
              {/* Height already locked */}
              <div
                style={{
                  background: "rgba(43,200,138,0.12)",
                  border: "1px solid rgba(43,200,138,0.3)",
                  borderRadius: 8,
                  padding: "6px 12px",
                  textAlign: "center",
                }}
              >
                <div style={{ fontSize: 9, color: K.muted, letterSpacing: 1 }}>
                  HEIGHT
                </div>
                <div style={{ fontSize: 16, fontWeight: 700, color: K.accent }}>
                  {heightFinal} cm
                </div>
              </div>
            </div>

            {/* Scale sensor panel */}
            <div
              style={{
                background: K.panel,
                borderRadius: 18,
                border: `1px solid ${
                  weightScanState === "done"
                    ? "rgba(43,200,138,0.5)"
                    : weightScanState === "stabilizing" ||
                        weightScanState === "locking"
                      ? "rgba(100,149,237,0.4)"
                      : K.border
                }`,
                padding: 28,
                textAlign: "center",
                marginBottom: 20,
                transition: "border-color 0.4s",
              }}
            >
              {/* HX711 icon */}
              <div
                style={{
                  display: "flex",
                  justifyContent: "center",
                  marginBottom: 16,
                }}
              >
                <div style={{ position: "relative", width: 80, height: 80 }}>
                  {(weightScanState === "stabilizing" ||
                    weightScanState === "locking") && (
                    <>
                      {[0, 1, 2].map((i) => (
                        <div
                          key={i}
                          style={{
                            position: "absolute",
                            inset: `${-i * 10}px`,
                            borderRadius: "50%",
                            border: `1px solid rgba(100,149,237,${0.5 - i * 0.15})`,
                            animation: `pulseRing 1.4s ease-out ${i * 0.35}s infinite`,
                          }}
                        />
                      ))}
                    </>
                  )}
                  {weightScanState === "done" && (
                    <>
                      {[0, 1].map((i) => (
                        <div
                          key={i}
                          style={{
                            position: "absolute",
                            inset: `${-i * 12}px`,
                            borderRadius: "50%",
                            border: `1px solid rgba(43,200,138,${0.4 - i * 0.15})`,
                            animation: `pulseRing 1.5s ease-out ${i * 0.4}s infinite`,
                          }}
                        />
                      ))}
                    </>
                  )}
                  <div
                    style={{
                      position: "absolute",
                      inset: 0,
                      borderRadius: "50%",
                      background:
                        weightScanState === "done"
                          ? "rgba(43,200,138,0.2)"
                          : weightScanState !== "idle"
                            ? "rgba(100,149,237,0.2)"
                            : K.accentDim,
                      display: "flex",
                      alignItems: "center",
                      justifyContent: "center",
                      border: `2px solid ${
                        weightScanState === "done"
                          ? K.accent
                          : weightScanState !== "idle"
                            ? "cornflowerblue"
                            : "rgba(43,200,138,0.3)"
                      }`,
                      transition: "all 0.4s",
                    }}
                  >
                    <Icon
                      name="scale"
                      size={30}
                      color={
                        weightScanState === "done"
                          ? K.accent
                          : weightScanState !== "idle"
                            ? "cornflowerblue"
                            : K.muted
                      }
                    />
                  </div>
                </div>
              </div>

              <div
                style={{
                  fontSize: 11,
                  color: K.muted,
                  letterSpacing: 1.5,
                  marginBottom: 8,
                }}
              >
                HX711 LOAD CELL AMPLIFIER
              </div>

              {/* Weight reading */}
              <div
                style={{
                  fontSize: 52,
                  fontWeight: 800,
                  color:
                    weightScanState === "done"
                      ? K.accent
                      : weightScanState !== "idle"
                        ? "cornflowerblue"
                        : K.faint,
                  fontVariantNumeric: "tabular-nums",
                  letterSpacing: -2,
                  marginBottom: 4,
                  minHeight: 64,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  gap: 6,
                  transition: "color 0.3s",
                  animation:
                    weightScanState === "stabilizing"
                      ? "weightFlicker 0.3s ease-in-out infinite"
                      : "none",
                }}
              >
                {weightReading !== null ? weightReading.toFixed(2) : "—"}
                <span
                  style={{
                    fontSize: 18,
                    fontWeight: 400,
                    color: K.muted,
                    letterSpacing: 0,
                  }}
                >
                  kg
                </span>
              </div>

              <div
                style={{
                  fontSize: 13,
                  color:
                    weightScanState === "done"
                      ? K.accent
                      : weightScanState === "locking"
                        ? "cornflowerblue"
                        : weightScanState === "stabilizing"
                          ? "rgba(100,149,237,0.9)"
                          : K.faint,
                  fontWeight: 600,
                  minHeight: 20,
                }}
              >
                {weightScanState === "idle" && "Ready to measure weight"}
                {weightScanState === "stabilizing" &&
                  "Reading load cell… hold still"}
                {weightScanState === "locking" && "Stabilizing value…"}
                {weightScanState === "done" &&
                  `✓ Weight locked — ${weightFinal} kg`}
              </div>

              {/* Load cell bar graph when active */}
              {(weightScanState === "stabilizing" ||
                weightScanState === "locking") && (
                <div
                  style={{
                    marginTop: 16,
                    display: "flex",
                    gap: 3,
                    alignItems: "flex-end",
                    justifyContent: "center",
                    height: 24,
                  }}
                >
                  {weightBarRandoms.map((rand, i) => (
                    <div
                      key={i}
                      style={{
                        width: 4,
                        borderRadius: 2,
                        background: "cornflowerblue",
                        height: `${rand.height}px`,
                        animation: `weightFlicker ${rand.duration}s ease-in-out infinite alternate`,
                        animationDelay: `${i * 0.05}s`,
                        opacity: 0.7,
                      }}
                    />
                  ))}
                </div>
              )}
            </div>

            {/* Action buttons */}
            {weightScanState === "idle" && (
              <button
                onClick={startWeightScan}
                style={{
                  width: "100%",
                  background: "cornflowerblue",
                  color: "#fff",
                  border: "none",
                  borderRadius: 12,
                  padding: "15px 0",
                  fontSize: 15,
                  fontWeight: 700,
                  cursor: "pointer",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  gap: 8,
                }}
              >
                <Icon name="scale" size={17} color="#fff" />
                START WEIGHT MEASUREMENT
              </button>
            )}

            {(weightScanState === "stabilizing" ||
              weightScanState === "locking") && (
              <div
                style={{
                  width: "100%",
                  background: "rgba(100,149,237,0.12)",
                  border: "1px solid rgba(100,149,237,0.25)",
                  borderRadius: 12,
                  padding: "14px 0",
                  fontSize: 14,
                  color: "cornflowerblue",
                  fontWeight: 600,
                  textAlign: "center",
                }}
              >
                Measuring… please wait
              </div>
            )}

            {weightScanState === "done" && (
              <button
                onClick={proceedToProcessing}
                style={{
                  width: "100%",
                  background: K.accent,
                  color: "#fff",
                  border: "none",
                  borderRadius: 12,
                  padding: "15px 0",
                  fontSize: 15,
                  fontWeight: 700,
                  cursor: "pointer",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  gap: 8,
                }}
              >
                Generate WHO Analysis
                <Icon name="arrowRight" size={16} color="#fff" />
              </button>
            )}

            <style>{`
              @keyframes pulseRing {
                0% { opacity: 1; transform: scale(1); }
                100% { opacity: 0; transform: scale(1.5); }
              }
              @keyframes weightFlicker {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
              }
            `}</style>
          </div>
        )}

        {/* ══ STEP 3: PROCESSING ═══════════════════════════════════════════════ */}
        {!showWelcome && step === 3 && (
          <div style={{ textAlign: "center", maxWidth: 400 }}>
            {/* Circular progress */}
            <div
              style={{
                position: "relative",
                width: 140,
                height: 140,
                margin: "0 auto 24px",
              }}
            >
              <svg
                width={140}
                height={140}
                style={{ position: "absolute", top: 0, left: 0 }}
              >
                <circle
                  cx={70}
                  cy={70}
                  r={60}
                  fill="none"
                  stroke={K.border}
                  strokeWidth={6}
                />
                <circle
                  cx={70}
                  cy={70}
                  r={60}
                  fill="none"
                  stroke={K.accent}
                  strokeWidth={6}
                  strokeLinecap="round"
                  strokeDasharray={`${2 * Math.PI * 60}`}
                  strokeDashoffset={`${2 * Math.PI * 60 * (1 - processProgress / 100)}`}
                  transform="rotate(-90 70 70)"
                  style={{ transition: "stroke-dashoffset 0.09s linear" }}
                />
              </svg>
              <div
                style={{
                  position: "absolute",
                  inset: 0,
                  display: "flex",
                  flexDirection: "column",
                  alignItems: "center",
                  justifyContent: "center",
                }}
              >
                <Icon name="activity" size={22} color={K.accent} />
                <div
                  style={{
                    color: K.text,
                    fontSize: 18,
                    fontWeight: 700,
                    marginTop: 4,
                  }}
                >
                  {Math.round(processProgress)}%
                </div>
              </div>
            </div>

            <div
              style={{
                color: K.accent,
                fontSize: 13,
                fontWeight: 600,
                marginBottom: 20,
              }}
            >
              {PROC_STAGES[processStage]}
            </div>

            {PROC_STAGES.map((s, i) => (
              <div
                key={i}
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 8,
                  fontSize: 11,
                  color: i <= processStage ? K.muted : K.faint,
                  marginBottom: 5,
                  justifyContent: "center",
                }}
              >
                {i < processStage ? (
                  <Icon name="check" size={11} color={K.accent} />
                ) : i === processStage ? (
                  <Icon name="arrowRight" size={11} color={K.accent} />
                ) : (
                  <svg width={11} height={11} viewBox="0 0 11 11" fill="none">
                    <circle
                      cx={5.5}
                      cy={5.5}
                      r={4.5}
                      stroke={K.faint}
                      strokeWidth={1.5}
                    />
                  </svg>
                )}
                {s}
              </div>
            ))}
          </div>
        )}

        {/* ══ STEP 4: RESULTS ══════════════════════════════════════════════════ */}
        {!showWelcome && step === 4 && result && selectedChild && (
          <div style={{ width: "100%", maxWidth: 560 }}>
            <div style={{ textAlign: "center", marginBottom: 22 }}>
              <div
                style={{
                  display: "flex",
                  justifyContent: "center",
                  marginBottom: 10,
                }}
              >
                <ResultIcon isNormal={result.status === "Normal"} />
              </div>
              <h2
                style={{
                  color: K.text,
                  fontSize: 20,
                  margin: "0 0 4px",
                  fontWeight: 700,
                }}
              >
                Measurement Complete
              </h2>
              <p style={{ color: K.muted, fontSize: 13, margin: 0 }}>
                {selectedChild.first_name} {selectedChild.last_name} ·{" "}
                {selectedChild.age_months} months old
              </p>
            </div>

            {/* Height + Weight cards */}
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "1fr 1fr",
                gap: 10,
                marginBottom: 12,
              }}
            >
              {[
                ["HEIGHT", heightFinal, "cm", K.accent, "scan"],
                ["WEIGHT", weightFinal, "kg", "cornflowerblue", "scale"],
              ].map(([label, val, unit, color, icon]) => (
                <div
                  key={label}
                  style={{
                    background: K.panel,
                    borderRadius: 12,
                    padding: "16px 14px",
                    border: `1px solid ${K.border}`,
                  }}
                >
                  <div
                    style={{
                      display: "flex",
                      alignItems: "center",
                      gap: 6,
                      marginBottom: 6,
                    }}
                  >
                    <Icon name={icon} size={13} color={color} />
                    <span
                      style={{ color: K.muted, fontSize: 10, letterSpacing: 1 }}
                    >
                      {label}
                    </span>
                  </div>
                  <div
                    style={{
                      color,
                      fontSize: 30,
                      fontWeight: 700,
                      fontVariantNumeric: "tabular-nums",
                    }}
                  >
                    {val}
                    <span
                      style={{ fontSize: 14, fontWeight: 400, color: K.muted }}
                    >
                      {" "}
                      {unit}
                    </span>
                  </div>
                </div>
              ))}
            </div>

            {/* Z-score results */}
            <div
              style={{
                background: K.panel,
                borderRadius: 14,
                padding: 18,
                border: `1px solid rgba(43,200,138,0.25)`,
                marginBottom: 12,
              }}
            >
              <div
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "center",
                  marginBottom: 14,
                }}
              >
                <div style={{ color: K.muted, fontSize: 11, letterSpacing: 1 }}>
                  NUTRITIONAL STATUS
                </div>
                <StatusBadge status={result.status} />
              </div>
              <div
                style={{
                  display: "grid",
                  gridTemplateColumns: "repeat(3,1fr)",
                  gap: 8,
                }}
              >
                {[
                  ["WAZ", result.waz, "#F5A623"],
                  ["HAZ", result.haz, "#6EA8DC"],
                  ["WHZ", result.whz, K.accent],
                ].map(([l, v, c]) => (
                  <div
                    key={l}
                    style={{
                      textAlign: "center",
                      background: "rgba(255,255,255,0.04)",
                      borderRadius: 10,
                      padding: "12px 6px",
                    }}
                  >
                    <div
                      style={{
                        color: c,
                        fontSize: 24,
                        fontWeight: 700,
                        fontVariantNumeric: "tabular-nums",
                      }}
                    >
                      {v > 0 ? "+" : ""}
                      {v}
                    </div>
                    <div
                      style={{
                        color: K.muted,
                        fontSize: 9,
                        marginTop: 4,
                        letterSpacing: 0.5,
                      }}
                    >
                      {l} Z-SCORE
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div style={{ display: "flex", gap: 10 }}>
              <button
                onClick={resetKiosk}
                style={{
                  flex: 1,
                  background: K.accent,
                  color: "#fff",
                  border: "none",
                  borderRadius: 10,
                  padding: "13px 0",
                  fontSize: 14,
                  cursor: "pointer",
                  fontWeight: 700,
                }}
              >
                New Measurement
              </button>
              <button
                onClick={onBack}
                style={{
                  flex: 1,
                  background: "rgba(224,49,49,0.14)",
                  color: K.danger,
                  border: `1px solid ${K.danger}55`,
                  borderRadius: 10,
                  padding: "13px 0",
                  fontSize: 14,
                  cursor: "pointer",
                  fontWeight: 700,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  gap: 8,
                }}
              >
                <Icon name="x" size={14} color={K.danger} /> Return to Login
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN APP
// ═══════════════════════════════════════════════════════════════════════════════
export default function App() {
  const [user, setUser] = useState(null);
  const [page, setPage] = useState(null);
  const [kioskMode, setKioskMode] = useState(false);
  const [toast, setToast] = useState(null);

  const [childrenData] = useState(INIT_CHILDREN);
  const [measurementsData] = useState(INIT_MEASUREMENTS);
  const [parentsData] = useState(INIT_PARENTS);
  const [usersData, setUsersData] = useState(INIT_USERS);
  const [auditLogs] = useState(INIT_AUDIT_LOGS);
  const [appointments] = useState(INIT_APPOINTMENTS);

  const showToast = (msg, type = "success") => setToast({ msg, type });

  const handleLogin = (u) => {
    setUser(u);
    setPage(u.role === "admin" ? "admin-dashboard" : "nutri-dashboard");
  };

  const ADMIN_NAV = [
    {
      label: "MAIN",
      items: [
        { id: "admin-dashboard", label: "Dashboard", icon: "dashboard" },
        { id: "admin-users", label: "User Management", icon: "users" },
      ],
    },
    {
      label: "HARDWARE",
      items: [
        { id: "admin-sensors", label: "Sensor Calibration", icon: "sensor" },
      ],
    },
    {
      label: "SECURITY",
      items: [
        {
          id: "admin-audit",
          label: "Audit Logs",
          icon: "audit",
          badge: auditLogs.filter((l) => l.level === "danger").length,
        },
        { id: "admin-roles", label: "Roles & Permissions", icon: "rolesIcon" },
      ],
    },
    {
      label: "CONFIG",
      items: [
        { id: "admin-settings", label: "System Settings", icon: "settings" },
      ],
    },
  ];

  const NUTRI_NAV = [
    {
      label: "OVERVIEW",
      items: [{ id: "nutri-dashboard", label: "Dashboard", icon: "dashboard" }],
    },
    {
      label: "HEALTH MONITORING",
      items: [
        { id: "nutri-children", label: "Children & Growth", icon: "children" },
        {
          id: "nutri-measurements",
          label: "Measurements",
          icon: "measurements",
        },
        { id: "nutri-who", label: "WHO Analysis", icon: "activity" },
      ],
    },
    {
      label: "COMMUNITY",
      items: [
        { id: "nutri-parents", label: "Parents", icon: "parents" },
        { id: "nutri-appointments", label: "Appointments", icon: "calendar" },
      ],
    },
    {
      label: "REPORTING",
      items: [{ id: "nutri-reports", label: "Reports", icon: "reports" }],
    },
    {
      label: "ACCOUNT",
      items: [{ id: "nutri-settings", label: "My Settings", icon: "settings" }],
    },
  ];

  if (kioskMode)
    return (
      <KioskView children={childrenData} onBack={() => setKioskMode(false)} />
    );
  if (!user)
    return (
      <LoginPage onLogin={handleLogin} onKiosk={() => setKioskMode(true)} />
    );

  const renderAdminPage = () => {
    switch (page) {
      case "admin-dashboard":
        return <AdminDashboard users={usersData} auditLogs={auditLogs} />;
      case "admin-users":
        return (
          <UserManagement
            users={usersData}
            showToast={showToast}
            onAdd={(u) =>
              setUsersData((p) => [
                ...p,
                {
                  ...u,
                  id: Math.max(...p.map((x) => x.id)) + 1,
                  last_login: "Never",
                },
              ])
            }
            onEdit={(u) =>
              setUsersData((p) =>
                p.map((x) => (x.id === u.id ? { ...x, ...u } : x)),
              )
            }
            onDelete={(u) =>
              setUsersData((p) => p.filter((x) => x.id !== u.id))
            }
          />
        );
      case "admin-sensors":
        return <SensorCalibration showToast={showToast} />;
      case "admin-audit":
        return <AuditLogs logs={auditLogs} />;
      case "admin-roles":
        return (
          <RolesPermissions
            users={usersData}
            showToast={showToast}
            onUpdateUserRole={(id, role) =>
              setUsersData((p) =>
                p.map((x) => (x.id === id ? { ...x, role } : x)),
              )
            }
          />
        );
      case "admin-settings":
        return <AdminSystemSettings showToast={showToast} />;
      default:
        return <AdminDashboard users={usersData} auditLogs={auditLogs} />;
    }
  };

  const renderNutriPage = () => {
    switch (page) {
      case "nutri-dashboard":
        return (
          <NutritionistDashboard
            children={childrenData}
            measurements={measurementsData}
            parents={parentsData}
            appointments={appointments}
          />
        );
      case "nutri-children":
        return (
          <ChildrenMonitoring
            children={childrenData}
            parents={parentsData}
            measurements={measurementsData}
            showToast={showToast}
          />
        );
      case "nutri-measurements":
        return (
          <MeasurementsPage
            measurements={measurementsData}
            children={childrenData}
            showToast={showToast}
          />
        );
      case "nutri-who":
        return (
          <WHOAnalysis
            children={childrenData}
            measurements={measurementsData}
          />
        );
      case "nutri-parents":
        return (
          <ParentsPage
            parents={parentsData}
            children={childrenData}
            showToast={showToast}
          />
        );
      case "nutri-appointments":
        return (
          <AppointmentsPage
            appointments={appointments}
            children={childrenData}
            parents={parentsData}
            showToast={showToast}
          />
        );
      case "nutri-reports":
        return (
          <ReportsPage
            children={childrenData}
            measurements={measurementsData}
          />
        );
      case "nutri-settings":
        return <NutritionistSettings user={user} showToast={showToast} />;
      default:
        return (
          <NutritionistDashboard
            children={childrenData}
            measurements={measurementsData}
            parents={parentsData}
            appointments={appointments}
          />
        );
    }
  };

  const isAdmin = user.role === "admin";

  return (
    <>
      {toast && (
        <Toast
          msg={toast.msg}
          type={toast.type}
          onClose={() => setToast(null)}
        />
      )}
      <AppShell
        user={user}
        navItems={isAdmin ? ADMIN_NAV : NUTRI_NAV}
        page={page}
        onNav={setPage}
        onLogout={() => {
          setUser(null);
          setPage(null);
        }}
        alerts={
          isAdmin
            ? auditLogs.filter((l) => l.level === "danger").length
            : childrenData.filter(
                (c) =>
                  c.status === "Severely Underweight" || c.status === "Wasted",
              ).length
        }
      >
        {isAdmin ? renderAdminPage() : renderNutriPage()}
      </AppShell>
    </>
  );
}
