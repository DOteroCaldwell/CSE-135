---
title: "HW 3 - Exploring Data Collection and Storage"
source: "https://canvas.ucsd.edu/courses/76795/assignments/1166818"
author:
published:
created: 2026-09-02
description:
tags:
  - "clippings"
---
HW 3 - Test Site, Data Collection, DB & REST Endpoints

---

As with the last assignments, read the full writeup before starting!

For max points, start early - while we try to give you the information needed, as we go further in the course we are less step by step - this assignment is more open ended the farther it goes and will require quite a bit of external research. It is purposefully not as structured in its requirements, so the onus of this assignment is on you making framework/library/design decisions!

Also, please make sure you are checking your error logs and Googling error messages before asking for help! Self-sufficiency here is a skill we can hone, let's work on it especially given you have a pretty built out tutorial that make many things easy a few things have been purposefully underspecified and are open for you to make decisions about.

## Learning Outcomes

---

- The student will collect data via logs and via a collector script.
- The student will be able to configure a database and manage data in a rudimentary manner.
- The student will understand REST and the benefits of mocking.
- The student will be able to create a simple REST style endpoint for data ingestion and retrieval.

## Part 1 - Building a Test Site

The first thing we need to do is build up a test site that we will use to put our analytical script on and log data from it. You can use an LLM to make a fake site of at least a few pages that contains from forms and has some interactive elements. If you build something fun it might make the assignment more amusing to you. The Prof provides the "Wrecked Tech" site for you to use if you rather. It's pretty messed up and was made with an LLM to be this way on purpose. You can find a Zip file with the assets for the site here - [wrecked-tech.zip](https://canvas.ucsd.edu/courses/76795/files/18787155?wrap=1 "wrecked-tech.zip") [Download wrecked-tech.zip](https://canvas.ucsd.edu/courses/76795/files/18787155/download?download_frd=1)

Make sure your "target" site is available at *test.yourdomain.* You likely will need to set up a vhost to do that.

**Get the site going and take a screen capture and submit it proving you have that going. Name this file target-site.jpg or whatever file format you use.**

## Part 2 - Logging the Test Site

Now you will want to make sure logging is enabled for the test site - this includes the logging access logs as well as error logs. You will certainly want to extend your logging and you will also need to figure out how to configure client hints ([https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Client\_hints Links to an external site.](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Client_hints)) Figure out how to configure that and get client hints logged.  
  
**Once you are collecting this richer log data take a screen capture of a snippet of your log file showing your enhanced logs name that file log-verify.jpg or other image type.**  

## Part 3 - The Collector Script

The diagram below demonstrates the basic flow of data - a user will enter your site at the homepage, and the request for the homepage file will include the request for **collector.js hosted on your collector.domainname vhost**. Once the page loads, the script will fire, sending the initial static and performance data to the endpoint. Then, any as the user interacts with the webpage, their activity data will be periodically sent to the endpoint. Your endpoint will store the data in the database, which you will then query to create your data grid and charts.

![135_Homework_Graphics_-_New_frame.jpg](https://canvas.ucsd.edu/courses/76795/files/18787212/preview)

This diagram represents the flow of data from your user to your analytics pages.

You will be creating your own **collector.js** script yourself; use the tutorial at the CSE135.site as there is a full walk through there. The script must collect the following data (it is allowed to collect more, but this is the minimum). This script should be served from your *collector.yourdomain.com* server

- Three Types of Data: Static, Performance, and Activity data
	- **Static** (collected after the page has loaded)
		- user agent string
				- the user's language
				- if the user accepts cookies
				- if the user allows JavaScript (you will have to manually figure this one out)
				- if the user allows images (you will have to manually figure this one out)
				- if the user allows CSS (you will have to manually figure this one out)
				- User's screen dimensions
				- User's window dimensions
				- User's network connection type
		- **Performance** (collected after the page has loaded)
		- The timing of the page load
			- The whole timing object
						- Specifically when the page started loading
						- Specifically when the page ended loading
						- The total load time (manually calculated - in milliseconds)
		- **Activity** (continuously collected)
		- All thrown errors
			- See window.onerror and various reporting scripts to grab this
				- All mouse activity [https://developer.mozilla.org/en-US/docs/Web/API/Element/mousemove\_event Links to an external site.](https://developer.mozilla.org/en-US/docs/Web/API/Element/mousemove_event)  
			- Cursor positions (coordinates)
						- Clicks (and which mouse button it was)
						- Scrolling (coordinates of the scroll)
				- All keyboard activity
				- [https://developer.mozilla.org/en-US/docs/Web/API/Element/keydown\_event Links to an external site.](https://developer.mozilla.org/en-US/docs/Web/API/Element/keydown_event).
			- Key down or Key up events
				- **Any idle time where no activity happened for a period of 2 or more seconds**
			- Record when the break ended
						- Record how long it lasted (in milliseconds)
				- When the user entered the page
				- When the user left the page
				- Which page the user was on  
			  
			You can send your data a number of ways, but the easiest is probably one of the following:
			- Fetch API: [https://developer.mozilla.org/en/docs/Web/API/Fetch\_API Links to an external site.](https://developer.mozilla.org/en/docs/Web/API/Fetch_API)
						- sendBeacon: [https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon Links to an external site.](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon) \[SUGGESTED\]
						- XHR: [https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest Links to an external site.](https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest)
			Do note that as mentioned in class there are terse more compacted ways to send data, but you may want to save this until you get it right.

**Challenging Point:** You must be able to tie this data to a specific user session - **THIS POINT IS HUGE and it gets into sessioning. You will find you may need to ponder how to tie logged data to script collected data. We will leave this for you to determine as there are many ways to do it. As we are giving you much of the collector script, this will be a part for you to figure.**

**Reach Point: For students wanting to explore this, we acknowledge that not every network request will work 100% of the time. You could, as the Prof described in lecture, collect the data locally, then make attempts to send updates to the server. This is not required.  
  
You will turn in your collector.js script it as a checkpoint deliverable.**

## Part 4 - Ingesting Your Data

You will need to show us that you can ingest data and store it in a database. There are numerous ways you can do this. You could push it to files and then write a script that every so often inserts it or you could have your collector script just call an endpoint /log on the collector vhost that logs data sent to it. That is likely easier to do. You are free to write this code in NodeJS or PHP as you like. That end point will receive data and insert it into MySQL or Postgresql ([https://www.postgresql.org/ Links to an external site.](https://www.postgresql.org/)).  
  
**Note:** If you'd like to learn some SQL and aren't familiar with it, this might be a nice time to take some time to orient yourself to commands. Surprisingly the tutorial from the W3Schools on this is pretty decent - [https://www.w3schools.com/sql/ Links to an external site.](https://www.w3schools.com/sql/). Try to do things by hand before you write code, but if I am honest you likely can do this directly with an LLM, but it might not stick to your memory much if you go that route.  
  
Verify that your data is being populated into some table you have designed and take a screen shot of some data in the database that can from your collector script. Name the file **database-verify.jpg (or other image type)**  

## Part 5 - Starting the REST endpoint

---

Once you feel you can send data to the server time to set up your REST endpoint on the reporting vhost. you have a choice of either NodeJS or php. You will have REST endpoints on your reporting.domainname vhost which will be used by the reporting backend in the next phase. We must get a start of that in this phase so we are on track.

Below we have an example routing system just for static user data. The example route of /api/static is not the exact routing scheme you have to choose for your table, you may choose to have something like /api/static/useragents or something to get more specific, it just depends on how you structure your database and what makes sense for you. What you **have** to do is follow the rest pattern as shown below. Making a GET request without an ID gets all for the given route, with an ID gets that specific ID. You must not include an ID when making a POST request, and you need to include an ID when making a DELETE and PUT request for a given route. Whatever routes you decide to make, make tables such as the one below explaining all of them, and put them in a.pdf called **example-routes.pdf**

| **HTTP Method** | **Example Route** | **Description** |
| --- | --- | --- |
| GET | /api/static | Retrieve every entry logged in the static table |
| GET | /api/static/{id} | Retrieve a specific entry logged in the static table (that matches the given id) |
| POST | /api/static | Add a new entry to the static table |
| DELETE | /api/static/{id} | Delete a specific entry from the static table (that matches the given id) |
| PUT | /api/static/{id} | Update a specific entry from the static table (that matches the given id) |

To demonstrate that your REST api is working, make a GET request to get your logged data. You can build a web page for that if you like or just use an endpoint testing tool like Postman to do this. If you start building a web page you are likely going to need a table or grid which is part of phase 2, but you can of course start early if you like. Do not stress yourself too hard on the routes, these are just some ones to get started with as you go you will likely change them.  
  
**Take a screenshot showing that data is returned from a database and label it REST.png and provide your example-routes.pdf.**

## Submission Details

---

You will be submitting everything to Gradescope. Include all the screen captures mentioned above, the collector.js script and the PDF file that shows your initial REST routes.

Finally, submit a README.md with the following:

- A link to your site
- Any necessary notes to the graders to make grading easier
- **team member names**
- **your IP address of server, ssh key, grader log in information to the site and server**
- Any changes you made to collector.js beyond ideas from the collector tutorial from the CSE135.site

<iframe src="about:blank" allowfullscreen="allowfullscreen" title="HW 3 - Exploring Data Collection and Storage" allow="geolocation *; microphone *; camera *; midi *; encrypted-media *; autoplay *; clipboard-write *; display-capture *; fullscreen *"></iframe>