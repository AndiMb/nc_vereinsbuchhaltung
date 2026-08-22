// Vergleich von "x.y.z"-Versionsnummern als Zahlen-Tupel statt als String -
// sonst waere "0.9.0" > "0.10.0". Frontend-Pendant zu
// WhatsNewService::versionCompare im Backend (beide bewusst unabhaengig
// implementiert, kein Teilen von Logik ueber die Sprachgrenze hinweg).

function segments(version) {
	const s = String(version || '')
	if (!/^\d+(\.\d+)*$/.test(s)) { return [0] }
	return s.split('.').map(Number)
}

export function compareVersions(a, b) {
	const pa = segments(a)
	const pb = segments(b)
	for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
		const diff = (pa[i] || 0) - (pb[i] || 0)
		if (diff !== 0) { return diff > 0 ? 1 : -1 }
	}
	return 0
}

export function isNewerVersion(a, b) {
	return compareVersions(a, b) > 0
}
