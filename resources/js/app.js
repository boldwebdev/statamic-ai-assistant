import AiTextarea from "./components/AiTextarea.vue";
import AiText from "./components/AiText.vue";
import BardAiButton from "./components/BardAiButton.vue";
import BardTranslationButton from "./components/BardTranslationButton.vue";
import { BoldAiBardService } from "./BoldAiBardService";

Statamic.booting(() => {
  Statamic.$components.register("ai_textarea-fieldtype", AiTextarea);

  Statamic.$components.register("ai_text-fieldtype", AiText);

  Statamic.$bard.addExtension(() => BoldAiBardService);

  Statamic.$bard.buttons((buttons) => {
    buttons.push({
      name: "BardManagement",
      text: "AI assistant",
      component: BardAiButton,
    });

    if (Statamic.$config.get("translationsActiv")) {
      buttons.push({
        name: "BardManagement",
        text: "AI translate",
        component: BardTranslationButton,
      });
    }
  });
});
