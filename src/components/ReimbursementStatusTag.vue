<template>
	<span class="vbh-typetag" :class="statusClass">{{ label }}</span>
</template>

<script>
import { reimbursementStatusClass, reimbursementStatusLabel } from '../lib/reimbursements.js'

export default {
	name: 'ReimbursementStatusTag',
	props: {
		status: { type: String, required: true },
	},

	computed: {
		label() { return reimbursementStatusLabel(this.status) },
		statusClass() { return reimbursementStatusClass(this.status) },
	},
}
</script>
