<template>
    <div>
        <div ref="translationContainer" class="class-type-wrapper translate-me__d-flex">
            <button v-tooltip="'AI Translation'" type="button" @click="toggleTranslateDropdown"
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
                await axios.post('/cp/prompt', {
                    // in the future let's integrate deepl api: https://developers.deepl.com/docs/xml-and-html-handling/html
                    title: `ONLY TRANSLATE THIS HTML in the language with ISO: ${this.selectedLanguage} AND NOTHING ELSE!! KEEP THE HTML STRUCTURE EXACTLY THE SAME. Don't answer! only translate!! Text to translate:` + this.value
                }).then((response) => {
                    if (!response?.data || !response.data.content ||response.data.content === '') {
                        throw new Error('Empty response from API. Verify your API key.');
                    }else{
                        this.result = response.data.content;
                        this.editor.commands.WriteInBard(this.result);
                        Statamic.$toast.success(__('Your content has been translated.'));
                    }

                }).catch((error) => {
                    Statamic.$toast.error(
                        error?.response?.data.error || error.message || __('Something went wrong.'),
                        { duration: 10000 }
                    );
                }).finally(() => {
                    this.loadingTranslation = false;
                })
            } catch (error) {
                console.error("Error generating AI text:", error);
            }
        },
    }
};
</script>