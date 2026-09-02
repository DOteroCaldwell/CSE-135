---
title: "HW 2 - Server-Side Basics and Analytics 3 Ways"
source: "https://canvas.ucsd.edu/courses/76795/assignments/1166817"
author:
published:
created: 2026-09-02
description:
tags:
  - "clippings"
---
HW 2 - Server Side Basics and Analytics 3+ Ways

Learning Outcomes

---

- Learn how to build server-side programs in multiple languages often as CGI style programs, demonstrating that the language does not deeply impact the request/response cycle of HTTP.
- Analyze the differences across the technologies, hopefully noting the sameness of abilities as it all just relies on HTTP requests/responses just with different levels of abstraction and language syntax.
- Analyze three different kinds of logging and analytics platforms to compare prebuilt analytical platforms to our potential projects. An over-all goal of the comparisons will be encourage thinking about buy-build-customize trade-offs for good solution finding in future efforts.

## Before You Begin

---

Read the FULL WRITEUP before starting and work slowly. Avoid temptation to paste into LLM coding tools as well. Many errors we have seen have been due to rushing, skipping steps or not doing close reads using or not using AI. While bugs may exist, bigger stumbling blocks appear to come from hastiness and may manifest in midterm test point loss as you may not get enough hands on to make ideas stick.

First up, make sure you have all the necessary technology installed on your server! Pick which languages you want to use and get them installed. Once installed sanity check the language there and make sure you are comfortable with the language if it is not already familiar to you. Do a non-web "hello world" or two with focus on reading and writing strings. Once you believe you are comfortable, Google "\<language> apache cgi hello world." Jumping right to the web execution of the homework will vary in ease from a little bumpy to potentially a grade and time destroying effort. You've been warned.

## Part 1: Get CGI Code Running

---

Go to [https://cse135.site/ Links to an external site.](https://cse135.site/). You will see example CGI programs done in many languages. Notice the Perl ones have source code available. Download the source files for the Perl example and get them running. Change the code to put your name or team name in the various "Hello Examples" and customize them if you like. This will make sure that you have CGI configured and roughly know what to do.

**Screenshot Alert! Once you have made this code work on your own, take a screenshot to show that they are running on your domain. Just take a screenshot for one of the "Hello World" Perl files that you download working. Make sure your screen shot shows your URL and title the files to the demo you capture - perl-hello-html-world.png, perl-hello-json.png, etc.**

## Part 2: Improved Version of the CGI Demo 3 Ways

---

This part of the homework presents an opportunity to prove to ourselves (or not) the Professor's proposition that languages and architectures used in Web programming are quite related to each other. Our small test programs hopefully will keep us from getting too caught up in the syntax and focus on the parts of Web development that are more similar than different and show the sameness in what these programs ingest and output.

You will write **examples** in different languages as follows:  
\* **hello-html- *language* - *name*** - this example just prints and HTML file containing a greeting from your team members, an indication of the language used, a date-time showing when the page was generated and the user's IP address.  
\* **hello-json- *language* -name** - this example does the same as the last but makes the response use a JSON format.  
\* **environment- *language* - *name*** - this example prints out the environment variables of the request  
\* **echo- *language* - *name*** - this example echos data sent from a form you have on your site.  
  
To test you will need an echo form web page which will contain a form that contains  
\* a language pull-down that allow you to target which echo endpoint you are hitting  
\* a method pull-down that allows you to set the HTTP method you are using. Make sure you use `GET`, `POST`, `PUT`, and `DELETE`. You may wish to change up the fields to make contextual sense for these  
\* an encoding pull-down that has minimally `x-www-form-urlencoded` and `application/json` as encoding choices to send  
\* a set of field(s) that you will send with your request  
\* a submit button  
  
When you fill out the form it will then submit to the endpoint selected the data you enter using the method and encoding specified. The endpoint will simply echo back what it received and the hostname, data and time, user agent header, and the IP address that was submitted to show that it was a unique and dynamic request to your site.  
  
Your echo form will need to employ JavaScript to do some of the logic such as adjusting the endpoint, method, and encoding. Make sure you provide logic that shows what can be done with script off. In other words you can make a demo that works with script on with many features, but in a reduced mode with JavaScript off. There are many ways to do this and some might even require multiple forms.  
  
**Note:** All JavaScript used is only allowed to be vanilla JS. No frameworks and no heavyweight solutions allowed. You will be writing these backend echo scripts yourself.  
  
\* **state- *language* - *name*** - this example allows you to enter data to save on one screen and you show it on other screens. There must be a way to clear the data.  
  
The state demo will also need HTML pages for collecting some data to save and another page (or two) where you can see the saved data. You will also need some way to clear the data. While you may be tempted to make a single set of pages for this that may make it harder so be careful. For state management you can implement it with *Cookies*, *Dirty URLs*, or *Hidden Form Fields*

- **NOTE:** This must be a *SERVER* side session, so do not simply store user info in `localStorage`. There is an opportunity for that in extra credit though if you read on.

Now you must do these examples in 3 different languages picked from the list below. If you do a NodeJS one you cannot do a second NodeJS one.

---

**Choose from the following languages:**

1. PHP
2. NodeJS (without Express - done manually) \[must be proxied by Apache!\]
3. NodeJS (with Express or similar) \[must be proxied by Apache!\]
4. JSP (Java)
5. Ruby (Ruby on Rails)
6. Python
7. Go
8. Rust
9. ColdFusion
10. C/C++  
	  
	**Important Note: You cannot choose Perl as demos were provided**

Create links on your `index.html` page under Homework 2 to link to each of these files on your server. Sort it by language - make sure you use semantic HTML for your link structures (check out lists - \<ul> or \<ol>). Make sure each of the programs can be reached via a link from `index.html`. The graders will *not* grade your programs if they are not reachable from your site homepage.  
  
An example of what your homepage could look like is shown below. Notice we have been doing variations from this assignment for some time and plenty of folks found it quite straight forward before AI so try not to default to that and rob yourself of mastery practice. Also you can make the page look better than this as you like - it's encouraged! **TL;DR - do not replicate this look, understand this as an example as the specifics this term are different actually**

![screenshot_4.PNG](https://canvas.ucsd.edu/courses/76795/files/18787218/preview) **Screen Shot Alert! Take a screen of your team main page call it hw2-main.png. Then you need to take a screenshot of each of the 5 demos working on your site as shown by a visible URL (15 total). Name each screen shot like the filename hello-html- *language* - *name* so you mighthave hello-html-php.png, hello-html-go.png, and so on.**

## Part 3: Third Party Analytics Done 3 Ways

---

The applied part of the assignment is demonstrates different approaches to third party analytics. You will go through setting up the most popular script based analytics (Google Analytics) and a powerful replay analytics system (LogRocket). Later assignments may show how we can use these or other services to execute our project in a less "code" focused manner

### Approach 1: Google Analytics

The first third party analytics system we are going to use is Google Analytics.

Use the Google Analytics Getting Started Guide: [https://support.google.com/analytics/answer/9304153?hl=en&ref\_topic=14088998&sjid=3860860151976788375-NC Links to an external site.](https://support.google.com/analytics/answer/9304153?hl=en&ref_topic=14088998&sjid=3860860151976788375-NC) or other material configure Google Analytics on your site  
Once you get it configured verify that you can collect some data and see it on your Google Analytics dashboard.  
  
**Screen Shot Alert! Take a screen once some data can be seen in GA and call it ga-dashboard.png.**

### Approach 2: LogRocket

For this step, we are going to see and test a live-tracking analytics service called LogRocket. First, go to [logrocket.com Links to an external site.](http://logrocket.com/) and click "Get Started Free." Follow the instructions to name your project (feel free to name it yourdomain.site). Use NPM or script and install logrocket agent. Get the script in some of your site's pages on your site and make sure it is running. Use your DevTools to see transmission and click around or scroll pages. If you go back to your LogRocket dashboard, there should be a recording of your session.

**Screenshot Alert! Take a screenshot of your LogRocket dashboard and label it logrocket.png. Take a screen recording of one of the sessions and save it as either logrocket-session.gif or logrocket-session.mp4 to show that it captures an actual replay.**

### Approach 3: Free Choice

There are many other types of services out there for analytics. For example, some solutions favor privacy issues, some integrate or focus on log files, and some provide very invasive analytics that might fingerprint or do one to one as opposed to roll up style analytics. You just have to find an analytics platform your team is interested in trying and try it. You must include a discussion of what you considered, why you picked this particular service, and any notes about how it went in evaluating it.

**Screenshot Alert! Take a screenshot of your free selection reporting system and label it free-choice.png.  
  
**

## Extra Credit: Modules or Fingerprinting

**Apache Modules**  
From a programming point of view most students will find little need to write an Apache Module (C/C++) and will stick to server-side scripting in PHP or NodeJS for their dev work. In this extra credit situation you may write a hello-module, environment-module, and echo-module. If you complete the three modules you also may investigate if it is possible to use Apache natively with existing modules to do sessioning and get that going, but do not write your own please. Write up a document EXTRA\_CREDIT\_APACHE\_MODULES.md which discusses each module you wrote and how to them. If you investigate how to session with Apache write up how you did that. The modules and write up is worth up to 10% bonus.  
  
**Fingerprinting**  
Users may clear cookies and in that case you may lose all sense of them when they return unless you have a forced login to reassociate them to previous activity. It is possible using the idea of JavaScript fingerprinting to identify a user if scripting is off. Research how to do fingerprinting and create a demo for it on your site. The best demo would combine your state demo with cookies and the fingerprinting and have logic showing you have identifying them and then how to reassociate them if they have cleared cookies. You will need some server tech here to do it well so leverage that. Because this can get rather involved you likely will use fingerprint software rather than write your own, but you could if you so desired. Write a document EXTRA\_CREDIT\_FINGERPRINT.md which explains what you did, how the demo works, and any limitations you encounter. The fingerprinting and write up is worth up to 10% bonus.  
  
You can do one extra credit or the other. If you do both for some reason do note you are capped at 10% extra. Note that the project does not require either of these ideas, but you could find them useful for the project especially if you are aiming to exceed the rubric minimums.  

## Submission

- README.md
	- a link to yourdomain.site; where under Homework 2, there are links to all of your CGI programs (the programs you/your group wrote as well as the ugly Perl code we provided to you)
		- a discussion of what your third approach was for 3rd party analytics, what you evaluated, why you picked the one you did, and your analysis of it
		- any additional notes for the graders
		- **team member names**
		- **your IP address of server, ssh key, grader log in information to the site and server**
- .png files for the main demos page (1), proof of the perl demo code working (1), each of the demo programs written three ways (15 total) - total 17
- The source code for your written programs either via Git or a Zip File
- ga-dashboard.png
- logrocket.png to show the logrocket dashboard
- Either logrocket-session.gif or logrocket-session.mp4 to show a captured session
- free-choice.png to show what you did to explore other analytics systems
- Extra credit readme(s) if you have them

<iframe src="about:blank" allowfullscreen="allowfullscreen" title="HW 2 - Server-Side Basics and Analytics 3 Ways" allow="geolocation *; microphone *; camera *; midi *; encrypted-media *; autoplay *; clipboard-write *; display-capture *; fullscreen *"></iframe>