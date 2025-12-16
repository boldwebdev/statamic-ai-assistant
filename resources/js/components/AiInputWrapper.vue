<template>
    <div class="bold-ai-statamic-assistant">

  
      <div ref="aiBoldContainer" class="ai-bold-wrapper">
      <!-- AI Icon Button -->
      <div class="ai-icon-wrapper">
        <button type="button" @click="openModal(() => internalValue)">
          <AiIcon class="w-5 h-5 text-blue v-popper--has-tooltip" />
        </button>
      </div>
  
      <!-- Translation Button & Dropdown -->
      <div
        v-show="languages.length > 0"
        :class="!internalValue ? 'opacity-50' : ''"
        class="translation-wrapper"
      >
        <button
          :disabled="!internalValue"
          type="button"
          @click="toggleTranslateDropdown"
          class="button-translate"
        >
          <Translation class="w-4 h-4 text-green-600" />
        </button>
        <div v-if="showTranslateDropdown" class="translate-dropdown vs__dropdown-menu">
          <div
            v-for="lang in languages"
            :key="lang.code"
            class="translate-option vs__dropdown-option"
            @click="translateTo(lang.code)"
          >
            {{ lang.label }}
          </div>
        </div>
      </div>
    </div>
          <!-- Main editor (textarea vs. input) -->
          <div class="relative">
        <input
          v-if="inputType === 'input'"
          key="input"
          ref="editor"
          v-model="internalValue"
          class="input-text"
        />
  
        <textarea
          v-else
          key="textarea"
          ref="editor"
          v-model="internalValue"
          rows="4"
          class="input-text"
        ></textarea>
  
        <!-- Loading overlay -->
        <div
          v-if="loadingTranslation"
          class="flex absolute top-0 left-0 justify-center items-center w-full h-full"
        >
          <span class="z-10 loader"></span>
          <div class="absolute top-0 left-0 w-full h-full bg-black rounded-sm opacity-50"></div>
        </div>
      </div>
  
      <!-- AI Prompt/Result/Refactor Modal -->
      <modal
        v-if="showModal"
        name="ai-prompt-modal"
        width="600px"
        height="auto"
        @closed="closeModal"
      >
        <div
          slot-scope="{ close }"
          class="relative p-4"
          @keyup.enter.ctrl="handleEnter(close)"
          @keyup.enter.meta="handleEnter(close)"
        >
          <button
            type="button"
            class="absolute text-gray-500 absolute-btn-close hover:text-gray-700"
            @click="closeModal(close)"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M6 18L18 6M6 6l12 12"
              ></path>
            </svg>
          </button>
  
          <!-- Prompt mode -->
          <div v-if="modalMode === 'prompt'">
            <h3 class="mb-2 text-lg font-bold">
              {{ transWithFallback('title', 'AI assistant') }}
            </h3>
            <p class="mb-4 help-block">
              {{ transWithFallback('prompt_placeholder', 'Describe the article you want to generate') }}
            </p>
            <textarea rows="4" v-model="prompt" class="input-text"></textarea>
          </div>
  
          <!-- Result mode -->
          <div v-else-if="modalMode === 'result'">
            <textarea
              :disabled="hadInitialValue"
              rows="4"
              ref="resulteditor"
              v-model="result"
              class="mt-1 input-text text-area-result"
            ></textarea>
          </div>
  
          <!-- Refactor mode -->
          <div v-else-if="modalMode === 'refactor'">
            <label class="block mb-1 font-bold">{{ transWithFallback('current_text', 'Current Text') }}</label>
            <p class="mb-4 help-block">
              {{ transWithFallback('prompt_placeholder', 'Describe the article you want to generate') }}
            </p>
            <textarea
              rows="4"
              class="mt-1 input-text text-area-result"
              :value="result"
              disabled
            ></textarea>
            <div class="flex justify-between items-center">
              <label class="block mt-4 mb-1 font-bold">
                {{ transWithFallback('edit', 'Describe your adjustment.') }}
                <span
                  class="inline-flex justify-center items-center ml-1 w-5 h-5 text-white rounded-full cursor-help"
                  :title="transWithFallback('refactor_description','Enter an instruction to update the current text.')"
                >?</span>
              </label>
            </div>
            <textarea
              rows="4"
              v-model="refactorPrompt"
              class="mt-1 input-text"
              :placeholder="transWithFallback('refactor_placeholder','Describe your adjustment')"
            ></textarea>
          </div>
  
          <!-- Modal buttons -->
          <div class="flex justify-between mt-4 space-x-2">
            <!-- Prompt -->
            <template v-if="modalMode === 'prompt'">
              <span></span>
              <button
                type="button"
                @click="submitPrompt(close)"
                class="btn-primary"
                :disabled="loading || prompt === ''"
              >
                <template v-if="loading"><span class="loader"></span></template>
                <span v-else>
                  {{ transWithFallback('go', 'go!') }} [<kbd>Ctrl</kbd> + <kbd>Enter</kbd>]
                </span>
              </button>
            </template>
  
            <!-- Result -->
            <template v-else-if="modalMode === 'result'">
              <button type="button" @click="resetToPrompt()" class="btn">
                {{ transWithFallback('back', 'Back') }}
              </button>
              <button type="button" @click="setRefactorMode()" class="btn">
                {{ transWithFallback('refactor', 'Refactor') }}
              </button>
              <button type="button" @click="validateResult(close)" class="btn-primary">
                {{ transWithFallback('apply_changes', 'Validate') }}
              </button>
            </template>
  
            <!-- Refactor -->
            <template v-else-if="modalMode === 'refactor'">
              <button type="button" @click="cancelRefactor()" class="btn">
                {{ transWithFallback('back', 'Back') }}
              </button>
              <button
                type="button"
                @click="submitRefactor(close)"
                class="btn-primary"
                :disabled="loading || refactorPrompt === ''"
              >
                <template v-if="loading"><span class="loader"></span></template>
                <span v-else>{{ transWithFallback('apply_refactor', 'Apply Refactor') }}</span>
              </button>
            </template>
          </div>
        </div>
      </modal>
    </div>
  </template>
  
  <script>
  import axios from "axios";
  import aiModalMixin from "../mixins/aiModalMixin";
  import aiTranslationMixin from "../mixins/aiTranslationMixin";
  import Translation from "./icons/TranslationIcon.vue";
  import AiIcon from "./icons/AiIcon.vue";
  
  export default {
    name: "AiInputWrapper",
    mixins: [Fieldtype, aiModalMixin, aiTranslationMixin],
    components: { Translation, AiIcon },
  
    props: {
      value: { type: String, default: "" },
      inputType: { type: String, default: "textarea" }, // "input" or "textarea"
    },
  
    data() {
      return {
        internalValue: this.value,
      };
    },
  
    watch: {
      // Sync local → parent
      internalValue(newVal) {
        this.update(newVal);
      },
      // Sync parent → local
      value(newVal) {
        if (newVal !== this.internalValue) {
          this.internalValue = newVal;
        }
      },
    },
  
    mounted() {
      this.getLocalizations();
      this.$nextTick(() => {
        // grid-cell need a special treatment
        const group = this.$el.closest('.grid-cell') || this.$el.closest('.form-group');
        if (!group) return;

        const label = group.querySelector('label');
        const container = this.$refs.aiBoldContainer;
        if (label && container) {
          // modern browsers
          if (label.prepend) {
            label.prepend(container);
          } else {
            // older browsers
            label.insertBefore(container, label.firstChild);
          }
        }
      });

      this.editor = {
        commands: { focus: () => this.$refs.editor.focus() },
        setEditable: e => (this.$refs.editor.disabled = !e),
      };
      this.resulteditor = {
        commands: { focus: () => this.$refs.resulteditor?.focus() },
      };
    },
  
    methods: {
      async submitPrompt(close) {
        if (!this.prompt) return;
        this.loading = true;
        try {
          let prompt = this.prompt;
          if(this.inputType === 'input'){
            prompt = "generate MAXIMUM 12 words! " + prompt;
          }
          const { data } = await axios.post("/cp/prompt", { title: prompt });
          if (!data.content || data.content.trim() === "") throw new Error("Empty response from API. Verify your API key");
          this.result = data.content;
          Statamic.$toast.success(__('Your content has been generated.'));
          this.modalMode = "result";
        } catch (err) {
          Statamic.$toast.error(err.response?.data.error || err.message, { duration: 10000 });
        } finally {
          this.loading = false;
          this.resulteditor.commands.focus();
        }
      },
  
      async submitRefactor(close) {
        if (!this.refactorPrompt) return;
        this.loading = true;
        try {
          let refactorPrompt = this.refactorPrompt;
          if(this.inputType === 'input'){
            refactorPrompt = "generate MAXIMUM 12 words!" + refactorPrompt;
          }
          const { data } = await axios.post("/cp/promptrefactor", {
            text: this.result,
            prompt: refactorPrompt,
          });
          if (!data.content || data.content.trim() === "") throw new Error("Empty response from API. Verify your API key");
          this.result = data.content;
          Statamic.$toast.success(__('Your content has been refactored.'));
          this.modalMode = "result";
        } catch (err) {
          Statamic.$toast.error(err.response?.data.error || err.message, { duration: 10000 });
        } finally {
          this.loading = false;
          this.resulteditor.commands.focus();
        }
      },
  
      validateResult(close) {
        this.update(this.result);
        this.editor.commands.focus();
        this.editor.setEditable(true);
        this.closeModal(close);
      },
    },
  };
  </script>
  
  <style>
  label:has(.ai-bold-wrapper){
    display: flex!important;
    align-items: center;
    gap: 10px;
  }

  .cursor-help {
    background: #242628;
  }

  .ai-bold-wrapper{
    display:flex;
    align-items: center;
    order: 2;
  }

  .ai-icon-wrapper,
  .translation-wrapper {
    border-radius: .25rem;
    padding: 0 .25rem;
    width: 28px;
    cursor: pointer;
  }

  .ai-icon-wrapper>button,
  .translation-wrapper>button {
    display: flex;
    justify-content: center;
    align-items: center;
    aspect-ratio: 1/1;
    height: 100%;
    width: 100%;
  }

  /* default (light) */
  :root .ai-icon-wrapper:hover,
  :root .translation-wrapper:hover {
    background-color: rgb(245 248 252 / var(--tw-bg-opacity));
  }

  /* dark theme */
  .dark .ai-icon-wrapper:hover,
  .dark .translation-wrapper:hover {
    background-color: rgb(36 38 40 / var(--tw-bg-opacity));
  }

  </style>
  