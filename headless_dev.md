# **Guide: Creating a Hands-Free Dev Loop with Gemini CLI**

This guide outlines the process for instructing your Gemini CLI to write, save, and test code for a dynamic, JavaScript-rendered local website. This workflow leverages your CLI's ability to directly execute local bash commands, creating a seamless code-test-debug cycle without you ever leaving the terminal.

### **1\. Prerequisites 🛠️**

Before you begin, ensure you have the following installed and configured:

* **The Gemini CLI:** You need the advanced version of the Gemini CLI that you've been using, which has the capability to execute local bash commands.
* **A Headless Browser:** For testing dynamic JavaScript pages, you need a browser that can be controlled from the command line. **Chrome**, **Chromium**, or **Edge** are excellent choices.
* **Full Path to Browser:** The agent may not be able to find your browser automatically. You must provide the full, absolute path to the browser's executable file.
  * **To find the path on Windows:** Right-click the browser's shortcut, select "Properties," and copy the entire path from the "Target" field. It will look something like `"C:\Program Files\Google\Chrome\Application\chrome.exe"`.
  * **To find the path on macOS:** Open the terminal and type `which chromium` or `which google-chrome`.
* **A Local Web Server:** You must have your WordPress or other web project running on a local server (e.g., via MAMP, XAMPP, or Local) and know its URL (e.g., http://localhost:8000).

### **2\. The Core Workflow 🔄**

The entire process is driven by a single, carefully constructed prompt. This "master prompt" instructs Gemini to perform a sequence of four distinct actions.

1. **Generate:** You first ask Gemini to create a piece of code (CSS, JS, PHP, etc.) based on your specific requirement.
2. **Save:** You then instruct it to use a bash command (like echo) to save that generated code directly into the correct file in your project's directory.
3. **Test:** Next, you tell it to run a command to test the result. For dynamic pages, this involves using a headless browser to load your local URL, execute all JavaScript, and dump the final, rendered HTML. This works for local files, local servers, and public websites.
4. **Analyze:** Finally, you instruct Gemini to capture the output from the test command and analyze it to determine if the initial goal was successfully achieved.

### **3\. The Master Prompt Template 📋**

You can use the following template for nearly any development task. Copy this structure and fill in the bracketed \[placeholders\] with the specifics of your request.

Please perform the following sequence of actions:

1\.  \*\*Generate Code:\*\* \[Your detailed request for the code you want. For example: "Create the JavaScript code to find the element with an ID of 'newsletter-signup' and change its background color to '#f0f8ff'."\]

2\.  \*\*Save the Code:\*\* Take the raw code generated in the previous step and write it to the local file at \[path/to/your/file\].

3.  \*\*Test the Page:\*\* Execute the following headless browser command to get the final, JavaScript-rendered HTML. **Remember to replace `[path/to/your/browser]` with the full path you found in the prerequisites.**
    `"[path/to/your/browser]" --headless --dump-dom [http://your-local-url-or-website]`

4\.  \*\*Analyze the Result:\*\* Capture the complete HTML output from the command in Step 3\. Carefully analyze this HTML to verify if \[describe the expected outcome. For example: "the element with the ID 'newsletter-signup' now has an inline style attribute containing 'background-color: rgb(240, 248, 255);'"\]. Report back on whether the task was successful. If it failed, explain why and provide the corrected code.

### **4\. A Complete Walkthrough Example 🚀**

Let's say you have a page at http://localhost:8080 and you want to use JavaScript to add a "new" badge to a product card.

**The Goal:** Use JavaScript to find a div with the class product-card and insert a `<span>` with the text "New!" inside it. The file to edit is `./js/app.js`.

#### **The Exact Prompt You Would Use:**

Please perform the following sequence of actions:

1\.  \*\*Generate Code:\*\* Create the JavaScript code to select the first element with the class "product-card" and append a new `<span>` element inside it. The span should have the text content "New!".

2\.  \*\*Save the Code:\*\* Take the raw code generated in the previous step and write it to the local file at `./js/app.js`.

3.  \*\*Test the Page:\*\* Execute the following headless browser command to get the final, JavaScript-rendered HTML from my local server. **Remember to replace `[path/to/your/browser]` with the full path you found in the prerequisites.**
    `"[path/to/your/browser]" --headless --dump-dom http://localhost:8080`

4\.  \*\*Analyze the Result:\*\* Capture the complete HTML output from the command in Step 3\. Carefully analyze this HTML to verify if an element with the class "product-card" now contains a `<span>New!</span>` element. Report back on whether the task was successful.