# HW 4 - Analytics Viz Platform

For your final assignment, you will be building out your own secured analytics dashboard and reporting system. This assignment is purposefully the least structured and most open ended of the assignments, which gives you a great opportunity to really show off what you've learned in this course!

---

## Learning Outcomes

* The student will learn the basics of building a server side MVC application in either JavaScript or PHP.
* The student will create a user centered dashboard and report page.
* The student will demonstrate basic competency of security with authentication and appropriate input checking.
* The student will describe clearly what they built and any design decisions made to demonstrate their thoughtful engineering.

---

## Part 0 - Project Overview

In this assignment, you add an analytics dashboard at `reporting.yourdomain.site`. The diagram below presents this flow visually. In words though, your users will be prompted with a login. Once they log in, they are either taken to the basic or admin view depending on their access level. From the main dashboard, you can generate a more detailed report. If you are an admin, there's a link to a user management page. You can also log out at any point and you will be taken to the logout screen.

### Page Flow Architecture

```
                    ┌────────────────────────┐
                    │      login.html        │
                    └───────────┬────────────┘
                                │
               ┌────────────────┴────────────────┐
               ▼                                 ▼
      (basic credentials)               (admin credentials)
  ┌─────────────────────────┐       ┌─────────────────────────┐
  │ index.html (basic user) │       │ index.html (admin user) │
  │  - Charts / Grids       │       │  - Charts / Grids       │
  │  - Link: generate report│       │  - Link: generate report│
  │  - Link: logout         │       │  - Link: user mgmt      │
  └────────────┬────────────┘       │  - Link: logout         │
               │                    └──────┬────────────┬─────┘
               │ (click report)            │            │
               ▼                           │            │ (click user mgmt)
  ┌─────────────────────────┐              │            ▼
  │   metricname.html       │              │    ┌─────────────────────────┐
  │   - Metric Details      │◄─────────────┘    │       users.html        │
  │   - Grid & Chart        │ (click report)    │  - CRUD User Grid       │
  │   - Link: logout        │                   │  - Link: logout         │
  └────────────┬────────────┘                   └───────────┬─────────────┘
               │                                            │
               │ (click logout)                             │ (click logout)
               └───────────────────┐    ┌───────────────────┘
                                   ▼    ▼
                       ┌───────────────────────┐
                       │      logout.html      │
                       │ ("You have            │
                       │   successfully        │
                       │   logged out")        │
                       └───────────────────────┘
```

> **NOTE:** File extensions used in this graph are arbitrary. Pages such as logout/login can easily have `.php` or even no extension as well.

---

## Part 1 - User Authentication

For this part, you will create a basic authentication system for logging into your reporting dashboard. This will consist of a couple of things:

* **login:** A login screen with two fields: One for the username/email and one for the password. (The username and email occupy the same field. They should be interchangeable with one another. The user can enter their username OR email to login, but not both. The same type of login most websites use.)
* **logout:** A confirmation page showing your user successfully logged out.
* **users (implemented in part 2):** A CRUD grid showing all users (username, email, hashed password, and an admin boolean) for admins to perform CRUD actions on users. **ONLY ADMINS can access this page.**

You can implement authentication however you'd like. This means you can use any language, framework, library, 3rd party plugin, or roll your own of course.

> **Important:** The graders (and anyone else) should **NOT** be able to access your reports without logging in!

### Submission Requirement
Create a section in your `README` titled **Authentication**. Provide a written explanation of your design decisions, such as how you are implementing auth and why you decided to use that implementation. Be thorough in your explanation to demonstrate to the graders that you explored your options and made a reasoned decision.

---

## Part 2 - User Management

Now that we have authentication, we want our admin users to be able to perform all the CRUD actions for user management so an admin should be able to:
* **Create** a new user
* **Read** (view) all users
* **Update** a user entry
* **Delete** a user (*When deleting a user, the admin should be prompted to confirm the deletion*)

Create a new page called `/users` to display your CRUD grid. Your CRUD grid can be made with any library.

### Grading Setup
For grading, create two accounts:
1. One basic level grader account
2. One admin level grader account

Provide these credentials in your `README`.

---

## Part 3 - Reporting Dashboard

Now that you have enabled user authentication to your site, you can build out your reporting dashboard. This should be at `reporting.yourdomain.site` (so the file you are editing should be `index.html`). You will be displaying the same data from Homework 3 using the same `collector.js` script and the same database from before.

Your dashboard should, at the **BARE MINIMUM**, contain **3 different metrics** with three different presentations (**2 charts and 1 grid**). You can use MORE charts and grids as you see fit. These charts must display data in a usable way, so make sure you choose your chart type carefully based on what kind of data you are displaying.

Charts may be static or dynamic, server or client rendered. Use any language/library/framework you'd like. Again, choose what works for you.

We will not give strict requirements on what the charts should look like because we want to see what **YOU** took away from the course:
* What data do you think is important?
* How should that data be displayed?
* Does it make sense to use a line chart or a bar chart?

You will be graded not only on the quality of your charts, but if you chose the appropriate chart type for your data, if the data is clear/styled well, etc.

> **Tip:** If someone who didn't build the chart can't figure out what it shows, you did it poorly. Ask a friend to take a look at your work.

Make sure your dashboard provides a way to access the User Management page you created in Part 2. On your dashboard, check to see if the logged in user is an Admin. If they are, display a link to the User Management page.

> **Warning:** Any charts/grids taken from Homework 3 must be improved upon in some way. You will lose points if you submit the same code from Homework 3 or just blindly copy over your `hellodataviz.html` page as your dashboard.

### Submission Requirement
Create a section in your `README` titled **Dashboard**. Provide a written explanation of your design decisions (which chart types you chose for which data, what metrics you decided to display and why, etc). Be thorough in your explanation to demonstrate to the teaching team that you explored your options and made your decisions based on legitimate reasoning and user centered thinking.

---

## Part 4 - Detailed Report

Now that your dashboard is built out to give users an overview of your data, pick one metric from your dashboard that you would like to create a detailed report on. Name the file `metricname.html` (or whatever extension you chose for your system) and provide a link from your dashboard to the report page.

Your report should answer a question. You must decide what that question is. For example:
* *"How many of my users are experiencing errors?"*
* *"What are my average web vitals scores?"*

Once you have your question in mind, craft your report around answering that question.

### Requirements
* The report should include **one grid and one chart MINIMUM**. You may include additional charts/grids as you see fit. The chart(s) and grid(s) must display your data in a way that helps to answer your guiding question.
* Provide a written discussion of some kind detailing what the data is showing and what that means for you/the user. This written discussion should answer your guiding question. This is intentionally open ended—we again want to see what you took away from the course and what you think is important as it pertains to analytics.
* Again, the way you use the grid and chart must be useful for a user and you will lose points if the chosen chart type is incorrect for your data or does not display your data in an understandable way.

### Submission Requirement
Create a section in your `README` titled **Report**. Provide a written explanation of your design decisions (which metric you decided to report on, which chart types you chose for which data, what metrics you decided to display and why, etc). Be thorough in your explanation to demonstrate to the teaching team that you explored your options and made your decisions based on legitimate reasoning and user centered thinking.

### Extra Credit Opportunity
Looking for extra credit? You can create as many detailed reports as you'd like. Name each file accordingly (`metricname.html`) and link to them from your dashboard. Extra credit will be given for additional reports at the graders' discretion — they must be high quality for credit!

Note in your `README` which report you are submitting for the assignment and we will assume the rest are extra credit attempts.

---

## Tips

* **Choose charts carefully:** Recall the *Data Viz and Coding Charts* lecture — the way you display your data matters!
* **Focus on usability:** Don't get too hung up on visual design decisions. Make your site usable — we want to see what you think user friendly design is! Just make sure you are explaining your design decisions in the appropriate portions of your README.
* **Quality over quantity:** Make one REALLY GOOD report instead of 10 low-effort ones. We won't give you extra credit for 9 additional low-effort reports. Again, we want to see that you have taken something away from the lectures and see if you can apply the ideas of the course yourself.
* **Styling:** When styling, be creative, take a chance! You can use any technology you'd like (Bootstrap is fine if that's what you're comfortable with!).

---

## Submission Checklist

Your submission must include:

1. **`README.md`**, including:
   * Site URL
   * Login info for grader (basic account)
   * Login info for grader (admin account)
   * Design decisions:
     * Authentication
     * Dashboard
     * Report (specify which report is primary if extra credit reports are included)
2. **ANY source code YOU WROTE**:
   * `login`
   * `logout`
   * `users`
   * `index`
   * `metricname.html` (or whatever extension you chose for your system)
   * ANY OTHER FILES YOU WROTE TO COMPLETE THIS ASSIGNMENT (does not include files submitted for past assignments)
