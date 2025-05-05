export default {
    data() {
      return {
        showModal: false,
        prompt: "",
        result: "",
        refactorPrompt: "",
        loading: false,
        // modalMode can be "prompt", "result", or "refactor"
        modalMode: "prompt",
        hadInitialValue: false
      };
    },
    methods: {
      transWithFallback(key, fallback) {
        // Common translation helper
        const translation = __("" + key);
        return translation === key ? fallback : translation;
      },
      openModal(getContentFn) {
        const currentContent = getContentFn ? getContentFn() : "";
        if (currentContent && currentContent.trim() !== "" && currentContent !== `<br class="ProseMirror-trailingBreak">`) {
          this.result = currentContent;
          this.modalMode = "refactor";
          this.hadInitialValue = true;
        } else {
          this.prompt = "";
          this.result = "";
          this.modalMode = "prompt";
          this.hadInitialValue = false;
        }
        this.refactorPrompt = "";
        this.showModal = true;
      },
      closeModal(close) {
        this.showModal = false;
        if (close) close();
      },
      resetToPrompt() {
        this.result = "";
        this.modalMode = "prompt";
      },
      setRefactorMode() {
        this.refactorPrompt = "";
        this.modalMode = "refactor";
      },
      cancelRefactor() {
        this.refactorPrompt = "";
        this.modalMode = "prompt";
      },
      handleEnter(close) {
        if (this.modalMode === "prompt" && !this.loading && this.prompt !== "") {
          this.submitPrompt(close);
        } else if (this.modalMode === "refactor" && !this.loading && this.refactorPrompt !== "") {
          this.submitRefactor(close);
        }
      }
    }
  };
  