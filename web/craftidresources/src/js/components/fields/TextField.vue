<template>
	<div class="form-group">
		<label :id="id" v-if="label">{{ label }}</label>

		<div v-if="instructions" class="instructions">
			<p>{{ instructions }}</p>
		</div>

		<text-input
			:autofocus="autofocus"
			:class="{'is-invalid': errors }"
			:disabled="disabled"
			:id="id"
			:placeholder="placeholder"
			:value="value"
			:mask="mask"
			@input="$emit('input', $event)"
			:autocapitalize="autocapitalize"
			:spellcheck="spellcheck"
			:readonly="readonly"
			ref="input"/>

		<div class="invalid-feedback" v-for="error in errors">{{ error }}</div>
	</div>
</template>

<script>
    import TextInput from '../inputs/TextInput';

    export default {

        props: ['label', 'id', 'placeholder', 'value', 'autofocus', 'errors', 'disabled', 'instructions', 'mask', 'autocapitalize', 'spellcheck', 'readonly'],

        components: {
            TextInput,
        },

        created() {
            this.$on('focus', function(msg) {
                this.$refs.input.$emit('focus');
            })
        }

    }
</script>
