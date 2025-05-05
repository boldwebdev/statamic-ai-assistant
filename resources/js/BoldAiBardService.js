import axios from "axios";
const { Node } = Statamic.$bard.tiptap.core;


export const BoldAiBardService = Node.create({
  name: "BardManagement",

  addCommands() {
    return {
      refactorHTMLWithAi: (attrs) => async (event) => {
         //todo: Would be better to manually change the text only of the html instead of trusting LLM for the HTML format
        const initialHTMLText = attrs.text;

        try {
          // Single API call for the entire combined text.
          const response = await axios.post("/cp/promptHtmlrefactor", {
            text: initialHTMLText,
            prompt: attrs.refactorPrompt,
          });

          // Return the refactored content if available.
          if (response?.data?.content) {
            return response.data.content;
          }

          // fallback to the original text.
          return initialHTMLText;
        } catch (error) {
          console.error("Error refactoring combined content:", error);
          return initialHTMLText;
        }
      },

      WriteInBard: (attrs) => async (event) => {
        const dom = event.editor.view.dom;
        if (Array.isArray(attrs)) {
          // Update each node individually with its corresponding content.
          //todo: check if tiptap function allow this to be made in an easier way
          attrs.forEach((content, index) => {
            if (dom.childNodes[index]) {
              dom.childNodes[index].innerHTML = content;
            }
          });
        } else {
          // Replace the entire container's content with the provided HTML.
          dom.innerHTML = attrs;
        }
      },

      // This function returns the whole HTML content of the Bard input
      // useful doc: https://tiptap.dev/docs/editor/api/editor#gethtml
      getBardContent: () => ({editor}) => {
        return editor.getHTML();
      },
    };
  },
});
