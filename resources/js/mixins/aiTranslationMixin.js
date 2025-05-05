import axios from "axios";

export default {
    data() {
      return {
        loadingTranslation: false,
        showTranslateDropdown: false,
        selectedLanguage: 'en',
        languages: [],
        value: null
      };
    },
    methods: {
      toggleTranslateDropdown() {
        this.showTranslateDropdown = !this.showTranslateDropdown;
      },
      async getLocalizations() {
        try {
          const response = await axios.get('/cp/getLocalizations');
          if (!response?.data || !response.data.content) return;
          const locales = response.data.content;
          // Format locales as needed.
          this.languages = Object.keys(locales).map(key => {
            const locale = locales[key];
            return {
              code: locale.short_locale,
              label: locale.name
            };
          });
        } catch (error) {
          console.error("Error fetching locales:", error);
        }
      },
      async translateTo(lang) {
        this.selectedLanguage = lang;
        this.showTranslateDropdown = false;
        this.loadingTranslation = true;
        try {
          await axios.post('/cp/prompt', {
            title: `ONLY TRANSLATE THIS ${this.translationMode || 'TEXT'} in the language with ISO: ${this.selectedLanguage} AND NOTHING ELSE!! Don't answer! only translate!! Text to translate:` + this.value
          }).then((response) => {
            if (!response?.data || !response.data.content) return;
            this.result = response.data.content;
            if (response.data.content === '') {
              throw new Error('Empty response from API. Service might be unavailable.');
            }
            // Depending on the component, either validate the result or write back.
            if (this.validateResult) {
              this.validateResult();
            } else if (this.editor && this.editor.commands && this.editor.commands.WriteInBard) {
              this.editor.commands.WriteInBard(this.result);
            }
            Statamic.$toast.success(__('Your content has been translated.'));
          }).catch((error) => {
            Statamic.$toast.error(
              error?.response?.data.error || error.message || __('Something went wrong.'),
              { duration: 10000 }
            );
          });
        } catch (error) {
          console.error("Error translating:", error);
        } finally {
          this.loadingTranslation = false;
        }
      },
      handleClickOutside(event) {
        // Assumes there is a ref named translationContainer.
        if (this.$refs.translationContainer && !this.$refs.translationContainer.contains(event.target)) {
          this.showTranslateDropdown = false;
        }
      }
    },
    mounted() {
      this.getLocalizations();
      document.addEventListener('click', this.handleClickOutside);
    },
    beforeDestroy() {
      document.removeEventListener('click', this.handleClickOutside);
    }
  };
  