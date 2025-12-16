import AiTextarea from "./components/AiTextarea.vue";
import AiText from "./components/AiText.vue";
import BardAiButton from "./components/BardAiButton.vue";
import BardTranslationButton from "./components/BardTranslationButton.vue";
import { BoldAiBardService } from "./BoldAiBardService";
import { TranslationInfoDisplay } from "./utils/TranslationInfoDisplay";

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


// Initialize translation-related services
Statamic.booted(() => {
  // Initialize translation info display
  const translationInfoDisplay = new TranslationInfoDisplay();
  translationInfoDisplay.init();
});

// Define the callback function for custom actions messages
Statamic.$callbacks.add('errorCallback', function (errorMessage) {
  Statamic.$toast.error(errorMessage);
});

Statamic.$callbacks.add('successCallback', function (redirectUrl, successMessage) {
  Statamic.$toast.success(successMessage);
  setTimeout(() => {
    window.location.href = redirectUrl;
  }, 1500);
});
