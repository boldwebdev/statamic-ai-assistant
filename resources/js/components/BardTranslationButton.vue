<template>
    <div>
        <div ref="translationContainer" class="class-type-wrapper translate-me__d-flex">
            <button v-tooltip="'DeepL Translation'" type="button" @click="toggleTranslateDropdown"
                class="bard-toolbar-button translate-me__btn translate-me__btn--bard">
                <Translation class="w-4 h-4 text-green-600" />
            </button>

            <div v-if="showTranslateDropdown" class="translate-dropdown vs__dropdown-menu">
                <div v-for="lang in languages" :key="lang.code"
                    class="translate-option vs__dropdown-option" @click="translateTo(lang.code)">
                    {{ lang.label }}
                </div>
            </div>
        </div>
        <div ref="loadingBard" :style="{ display: loadingTranslation ? 'flex' : 'none' }"
            class="absolute top-0 left-0 z-10 justify-center items-center w-full h-full">
            <span class="z-10 loader"></span>
            <div class="absolute top-0 left-0 w-full h-full bg-black rounded-sm opacity-50"></div>
        </div>

    </div>
</template>

<script>
import axios from "axios";
import Translation from "./icons/TranslationIcon.vue";
import aiTranslationMixin from "../mixins/aiTranslationMixin";

export default {
    mixins: [BardToolbarButton, aiTranslationMixin],
    name: "BardAiTranslation",
    components: { Translation },
    data() {
        return {
            value: null,
            loadingTranslation: false,
            showTranslateDropdown: false,
            selectedLanguage: "en",
            languages: []
        };
    },
    mounted() {
        this.getLocalizations();
        document.addEventListener("click", this.handleClickOutside);
        this.$nextTick(() => {
            const groupNode = this.$el.closest('.bard-fieldtype-wrapper');
            if (groupNode && this.$refs.loadingBard) {
                groupNode.appendChild(this.$refs.loadingBard);
            }
        });
    },
    beforeDestroy() {
        document.removeEventListener("click", this.handleClickOutside);
    },
    methods: {
        toggleTranslateDropdown() {
            let result = this.editor.commands.getBardContent()
            if (result && result !== "") {
                this.value = result;
            }

            this.showTranslateDropdown = !this.showTranslateDropdown;
        },
        async translateTo(lang) {
            this.selectedLanguage = lang;
            this.showTranslateDropdown = false;
            this.loadingTranslation = true;

            try {
                const response = await axios.post('/cp/ai-translations/field', {
                    text: this.value,
                    target_locale: lang,
                });

                if (!response?.data?.translated || response.data.translated === '') {
                    throw new Error('Empty response from DeepL. Verify your API key.');
                }

                this.result = response.data.translated;
                this.editor.commands.WriteInBard(this.result);
                Statamic.$toast.success(__('Your content has been translated.'));
            } catch (error) {
                Statamic.$toast.error(
                    error?.response?.data?.error || error.message || __('Translation failed.'),
                    { duration: 10000 }
                );
            } finally {
                this.loadingTranslation = false;
            }
        },
    }
};
</script>
