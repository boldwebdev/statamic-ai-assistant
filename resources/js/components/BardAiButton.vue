<template>
    <div>
        <div class="class-type-wrapper translate-me__d-flex">
            <button class="bard-toolbar-button translate-me__btn translate-me__btn--bard"
                v-tooltip="tooltipText" @click="openModal(editor.commands.getBardContent)" :disabled="disabled || loading">
                <!-- Display the AI icon -->
                <AiIcon class="w-5 h-5 text-blue" />
            </button>
        </div>

        <!-- Modal for AI prompt / result / refactor -->
        <modal v-if="showModal" name="ai-prompt-modal" width="600px" height="auto"
            @closed="closeModal">
            <div slot-scope="{ close }" class="relative p-4" @keyup.enter.ctrl="handleEnter(close)"
                @keyup.enter.meta="handleEnter(close)">
                <button type="button"
                    class="absolute text-gray-500 absolute-btn-close hover:text-gray-700"
                    @click="closeModal(close)">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>

                <!-- Render content based on modalMode -->
                <div v-if="modalMode === 'prompt'">
                    <h3 class="mb-2 text-lg font-bold">{{ transWithFallback('title', 'AI assistant')
                    }}</h3>
                    <p class="mb-4 help-block">
                        {{ transWithFallback('prompt_placeholder', 'Describe the content you want to generate') }}
                    </p>
                    <textarea rows="4" v-model="prompt" class="input-text"
                        placeholder=""></textarea>
                </div>
                <div v-else-if="modalMode === 'result'">
                    <div :disabled="hadInitialValue" rows="4" ref="resulteditor" v-html="result"
                        class="mt-1 prose input-text text-area-result" placeholder=""></div>
                </div>
                <div v-else-if="modalMode === 'refactor'">
                    <label class="block mb-1 font-bold">{{ transWithFallback('current_text', 'Current Text') }}</label>
                    <p class="mb-4 help-block">
                        {{ transWithFallback('refactor_description', 'Describe the changes you want to make') }}
                    </p>
                    <div :disabled="hadInitialValue" rows="4" ref="resulteditor" v-html="result"
                        class="mt-1 prose input-text text-area-result" placeholder=""></div>
                        <div class="flex justify-between items-center">
                    <label class="block mt-4 mb-1 font-bold">
                        {{ transWithFallback('edit', 'Describe your adjustment.') }}
                        <span
                        class="inline-flex justify-center items-center ml-1 w-5 h-5 text-white rounded-full cursor-help"
                        :title="transWithFallback('refactor_description','Enter an instruction to update the current text.')"
                        >?</span>
                    </label>
                    </div>
                    <textarea rows="4" v-model="refactorPrompt" class="mt-1 input-text"
                        :placeholder="transWithFallback('refactor_placeholder', 'Describe your changes')"></textarea>
                </div>

                <!-- Buttons area -->
                <div class="flex justify-between mt-4 space-x-2">
                    <template v-if="modalMode === 'prompt'">
                        <span></span>
                        <button type="button" @click="submitPrompt(close)" class="btn-primary"
                            :disabled="loading || prompt === ''">
                            <template v-if="loading">
                                <span class="loader"></span>
                            </template>
                            <span v-else>
                                {{ transWithFallback('go', 'go!') }}
                            </span>
                        </button>
                    </template>
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
                    <template v-else-if="modalMode === 'refactor'">
                        <button type="button" @click="cancelRefactor()" class="btn">
                            {{ transWithFallback('back', 'Back') }}
                        </button>
                        <button type="button" @click="submitRefactor(close)" class="btn-primary"
                            :disabled="loading || refactorPrompt === ''">
                            <template v-if="loading">
                                <span class="loader"></span>
                            </template>
                            <span v-else>
                                {{ transWithFallback('apply_refactor', 'Generieren') }}
                            </span>
                        </button>
                    </template>
                </div>
            </div>
        </modal>
    </div>
</template>

<script>
import axios from "axios";
import AiIcon from "./icons/AiIcon.vue";
import aiModalMixin from "../mixins/aiModalMixin";

export default {
    mixins: [BardToolbarButton,aiModalMixin],
    name: "BardAiButton",
    components: {
        AiIcon
    },
    props: {
        config: Object,
        disabled: Boolean
    },
    data() {
        return {
            value: null,
            showModal: false,
            prompt: "",
            result: "",
            refactorPrompt: "",
            loading: false,
            // modalMode can be "prompt", "result", or "refactor"
            modalMode: "prompt",
            hadInitialValue: false,
            tooltipText: "AI Assistant"
        };
    },
    methods: {
        async submitPrompt(close) {
            if (!this.prompt) return;
            this.loading = true;
            try {
                const response = await axios.post("/cp/prompt", { title: this.prompt });
                if (!response?.data || !response.data.content) return;
                this.result = response.data.content;
                if (response.data.content === "") {
                    throw new Error("Empty response from API. Verify your API key.");
                }
                this.modalMode = "result";
            } catch (error) {
                console.error("Error generating AI text:", error);
            } finally {
                this.loading = false;
            }
        },
        async submitRefactor(close) {
            if (!this.refactorPrompt) return;
            this.loading = true;
            try {
                const newHTML = await this.editor.commands.refactorHTMLWithAi({
                    text: this.result,
                    refactorPrompt: this.refactorPrompt,
                });
                if (!newHTML || newHTML.trim() === "") {
                    Statamic.$toast.error('Empty response from API. Verify your API key', { duration: 10000 });
                }
                else{
                    this.result = newHTML;
                    Statamic.$toast.success(__('Your content has been refactored.'));
                    this.modalMode = "result";
                }
            } catch (error) {
                console.error("Error refactoring AI text:", error);
            } finally {
                this.loading = false;
            }
        },
        validateResult(close) {
            console.log("writebard:", this.result)
            this.editor.commands.WriteInBard(this.result);
            this.closeModal(close);
        },
    }
};
</script>
